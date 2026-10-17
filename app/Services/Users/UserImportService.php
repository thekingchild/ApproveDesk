<?php

namespace App\Services\Users;

use App\Enums\SystemRole;
use App\Models\Department;
use App\Models\User;
use App\Services\Roles\RoleAssignmentService;
use App\Support\E164Phone;
use App\Support\UserImportReadFilter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

/**
 * CSV/XLSX user import for large-organization onboarding (Phase UO.1).
 *
 * Pipeline: rows() -> analyze() [dry-run report] -> commit(). Rows carry
 * per-row status so one bad line never aborts the batch. Passwords are
 * generated (never imported) and emails are case-insensitively unique.
 */
class UserImportService
{
    public const HEADER = ['name', 'email', 'department', 'manager_email', 'phone', 'roles'];

    private const CHUNK = 200;

    public function __construct(
        private readonly GrantableRoleService $roles,
        private readonly RoleAssignmentService $assignments,
        private readonly ActiveUserLimitService $limits,
        private readonly UserSpreadsheetImportGuard $spreadsheetGuard,
    ) {}

    /**
     * Parse an uploaded file into normalized row arrays.
     *
     * @return list<array<string, string>>
     */
    public function rows(string $absolutePath, string $extension): array
    {
        $rows = match (mb_strtolower($extension)) {
            'xlsx', 'xls' => $this->spreadsheetRows($absolutePath),
            'csv', 'txt' => $this->csvRows($absolutePath),
            default => throw new RuntimeException(__('Unsupported file type. Upload a .csv or .xlsx file.')),
        };

        // Skip completely empty trailing rows.
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => mb_strtolower(trim((string) $row['name'])) !== '' || trim((string) $row['email']) !== '',
        ));
    }

    /**
     * Dry-run: validate every row against current data without writing.
     *
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, mixed>> each: row number, data, status, message
     */
    public function analyze(array $rows, User $actor): array
    {
        Gate::forUser($actor)->authorize('users.import');

        $existingEmails = User::query()->pluck('email')->map(fn (string $email): string => mb_strtolower($email))->all();
        $departmentNames = Department::query()->pluck('name')->map(fn (string $name): string => mb_strtolower(trim($name)))->all();
        $existingPhones = User::query()->whereNotNull('phone')->pluck('email', 'phone')->map(fn (string $email): string => mb_strtolower($email))->all();
        // Earlier lines of this file, by email: line number and whether it will be written (RV-BUG-25).
        $earlierRows = [];
        $phonesInFile = [];
        $reports = [];

        foreach ($rows as $index => $row) {
            $report = $row;
            $line = $index + 2; // header is line 1
            $name = trim((string) $report['name']);
            $email = mb_strtolower(trim((string) $report['email']));
            $department = trim((string) ($report['department'] ?? ''));
            $managerEmail = mb_strtolower(trim((string) ($report['manager_email'] ?? '')));
            $phone = E164Phone::normalize((string) ($report['phone'] ?? '')) ?? '';
            $roles = collect(preg_split('/[;,]+/', (string) ($report['roles'] ?? '')) ?: [])
                ->map(fn (string $role): string => trim($role))
                ->filter()
                ->values()
                ->all();

            if ($name === '') {
                $reports[] = [...$report, 'line' => $line, 'status' => 'error', 'message' => __('Name is required.')];

                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $reports[] = [...$report, 'line' => $line, 'status' => 'error', 'message' => __('A valid email is required.')];

                continue;
            }

            $isDuplicate = in_array($email, $existingEmails, true);

            // Rows for existing accounts get the same checks as new ones, because
            // "Update the existing account" writes them (RV-BUG-24).
            $problem = $this->rowProblem($actor, $email, $department, $departmentNames, $roles, $phone, $existingPhones, $phonesInFile, $managerEmail, $existingEmails, $earlierRows);

            if ($problem !== null) {
                $reports[] = [...$report, 'line' => $line, 'status' => 'error', 'message' => $isDuplicate
                    ? __('An account with this email already exists, but this row cannot update it: :problem', ['problem' => $problem])
                    : $problem];
                $earlierRows[$email] ??= ['line' => $line, 'usable' => $isDuplicate];

                continue;
            }

            if ($phone !== '') {
                $phonesInFile[$phone] = $email;
            }

            $report['phone'] = $phone;
            if (! ($earlierRows[$email]['usable'] ?? false)) {
                $earlierRows[$email] = ['line' => $line, 'usable' => true];
            }

            $reports[] = $isDuplicate
                ? [...$report, 'line' => $line, 'status' => 'duplicate', 'message' => __('An account with this email already exists.')]
                : [...$report, 'line' => $line, 'status' => 'ready', 'message' => ''];
        }

        $remaining = $this->limits->remaining();
        if ($remaining !== null) {
            $newReady = 0;
            foreach ($reports as $index => $report) {
                if (($report['status'] ?? '') !== 'ready') {
                    continue;
                }

                $newReady++;
                if ($newReady > $remaining) {
                    $reports[$index]['status'] = 'error';
                    $reports[$index]['message'] = __('The active-user limit has been reached.');
                }
            }
        }

        return $this->refuseRowsWhoseManagerWillNotBeCreated($reports, $existingEmails);
    }

    /**
     * Field checks shared by new and existing-account rows. Returns the first
     * problem, or null when the row can be written.
     *
     * @param  array<int, string>  $departmentNames
     * @param  array<int, string>  $roles
     * @param  array<string, string>  $existingPhones  phone => owner email
     * @param  array<string, string>  $phonesInFile  phone => email of an earlier usable row
     * @param  array<int, string>  $existingEmails
     * @param  array<string, array{line: int, usable: bool}>  $earlierRows
     */
    private function rowProblem(
        User $actor,
        string $email,
        string $department,
        array $departmentNames,
        array $roles,
        string $phone,
        array $existingPhones,
        array $phonesInFile,
        string $managerEmail,
        array $existingEmails,
        array $earlierRows,
    ): ?string {
        if ($department !== '' && ! in_array(mb_strtolower($department), $departmentNames, true)) {
            return __('Department ":department" does not exist.', ['department' => $department]);
        }

        foreach ($roles as $role) {
            if (! in_array($role, $this->roles->for($actor), true)) {
                return __('Role ":role" cannot be granted by you or does not exist.', ['role' => $role]);
            }
        }

        if ($phone !== '' && ! E164Phone::isValid($phone)) {
            return __('Phone must be international format, e.g. +2348012345678.');
        }

        if ($phone !== '' && ((isset($existingPhones[$phone]) && $existingPhones[$phone] !== $email)
            || (isset($phonesInFile[$phone]) && $phonesInFile[$phone] !== $email))) {
            return __('Phone :phone already belongs to another person.', ['phone' => $phone]);
        }

        if ($managerEmail === '') {
            return null;
        }

        if ($managerEmail === $email) {
            return __('A person cannot be their own manager.');
        }

        if (! in_array($managerEmail, $existingEmails, true)) {
            if (! isset($earlierRows[$managerEmail])) {
                return __('Manager :email is not an existing user and does not appear earlier in this file.', ['email' => $managerEmail]);
            }

            // The manager's own row failed, so they will not exist (RV-BUG-25).
            if (! $earlierRows[$managerEmail]['usable']) {
                return __('Manager :email is on line :line, which cannot be imported. Fix that line first.', ['email' => $managerEmail, 'line' => $earlierRows[$managerEmail]['line']]);
            }

            return null;
        }

        $existingManager = User::query()->where('email', $managerEmail)->first();
        if ($existingManager instanceof User && $existingManager->isSystemOwner()) {
            return __('That manager is not available for assignment.');
        }

        return null;
    }

    /**
     * A row whose manager is created by an earlier row in this file can only
     * be written if that row is still going to be written; the active-user
     * limit can refuse rows after the first pass (RV-BUG-25).
     *
     * @param  list<array<string, mixed>>  $reports
     * @param  array<int, string>  $existingEmails
     * @return list<array<string, mixed>>
     */
    private function refuseRowsWhoseManagerWillNotBeCreated(array $reports, array $existingEmails): array
    {
        $refusedLines = [];

        foreach ($reports as $index => $report) {
            $email = mb_strtolower(trim((string) ($report['email'] ?? '')));

            if (($report['status'] ?? '') === 'error') {
                $refusedLines[$email] ??= (int) ($report['line'] ?? 0);

                continue;
            }

            $managerEmail = mb_strtolower(trim((string) ($report['manager_email'] ?? '')));

            if ($managerEmail === '' || in_array($managerEmail, $existingEmails, true) || ! isset($refusedLines[$managerEmail])) {
                continue;
            }

            $reports[$index]['status'] = 'error';
            $reports[$index]['message'] = __('Manager :email is on line :line, which cannot be imported. Fix that line first.', ['email' => $managerEmail, 'line' => $refusedLines[$managerEmail]]);
            $refusedLines[$email] ??= (int) ($report['line'] ?? 0);
        }

        return $reports;
    }

    /**
     * Write the ready rows inside a transaction. Returns counts.
     *
     * @param  list<array<string, mixed>>  $analyzed
     * @param  'skip'|'update'  $duplicateMode
     * @return array{created: int, skipped: int, updated: int}
     */
    public function commit(array $analyzed, User $actor, string $duplicateMode = 'skip'): array
    {
        Gate::forUser($actor)->authorize('users.import');

        $counts = ['created' => 0, 'skipped' => 0, 'updated' => 0];
        $departments = Department::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name): array => [mb_strtolower(trim($name)) => $id]);

        $this->limits->runActivation(0, function () use ($analyzed, $actor, $duplicateMode, &$counts, $departments): void {
            $needed = collect($analyzed)
                ->filter(fn (array $row): bool => ($row['status'] ?? '') === 'ready')
                ->count();

            if ($needed > 0) {
                $this->limits->runActivation($needed, function (): void {});
            }

            foreach (array_chunk($analyzed, self::CHUNK) as $chunk) {
                foreach ($chunk as $row) {
                    $status = (string) ($row['status'] ?? '');

                    if ($status === 'error') {
                        continue;
                    }

                    $email = mb_strtolower(trim((string) $row['email']));
                    $departmentId = isset($row['department']) && trim((string) $row['department']) !== ''
                        ? $departments->get(mb_strtolower(trim((string) $row['department'])))
                        : null;
                    $managerEmail = mb_strtolower(trim((string) ($row['manager_email'] ?? '')));
                    $manager = $managerEmail === '' ? null : User::query()->assignable()->where('email', $managerEmail)->first();
                    $roles = [];
                    foreach (preg_split('/[;,]+/', (string) ($row['roles'] ?? '')) ?: [] as $role) {
                        $role = trim((string) $role);
                        if ($role !== '') {
                            $roles[] = $role;
                        }
                    }

                    $existing = User::query()->where('email', $email)->first();

                    if ($existing !== null) {
                        if ($existing->isSystemOwner()) {
                            $counts['skipped']++;

                            continue;
                        }

                        if ($duplicateMode === 'update') {
                            // Same rules as editing on the Users screen (RV-SEC-06): importing
                            // must not let someone change people they could not edit by hand.
                            if (! Gate::forUser($actor)->allows('update', $existing)
                                || (! $actor->can('system_owner') && $existing->hasAnyRole([SystemRole::Admin->value, SystemRole::SuperAdmin->value]))) {
                                $counts['skipped']++;

                                continue;
                            }

                            if ($roles !== []) {
                                $this->roles->assertCanSync($actor, $existing, $roles);
                            }

                            $existing->forceFill([
                                'name' => trim((string) $row['name']),
                                'phone' => trim((string) ($row['phone'] ?? '')) ?: $existing->phone,
                                'department_id' => $departmentId ?? $existing->department_id,
                                'manager_id' => $manager?->id ?? $existing->manager_id,
                            ])->save();

                            if ($roles !== []) {
                                $this->assignments->sync($actor, $existing, $roles);
                            }

                            $counts['updated']++;
                        } else {
                            $counts['skipped']++;
                        }

                        continue;
                    }

                    $user = User::create([
                        'name' => trim((string) $row['name']),
                        'email' => $email,
                        'phone' => trim((string) ($row['phone'] ?? '')) ?: null,
                        'password' => Hash::make(Str::password(16)),
                        'department_id' => $departmentId,
                        'manager_id' => $manager?->id,
                        'is_active' => true,
                        // Imported users are organization-managed; they are
                        // trusted immediately regardless of the registration OTP mode.
                        'email_verified_at' => now(),
                    ]);

                    if ($roles !== []) {
                        $this->roles->assertCanAssign($actor, $roles);
                        $this->assignments->sync($actor, $user, $roles);
                    }

                    $counts['created']++;
                }
            }
        });

        Log::info('users.imported', [
            'actor_id' => $actor->id,
            'created' => $counts['created'],
            'skipped' => $counts['skipped'],
            'updated' => $counts['updated'],
            'duplicate_mode' => $duplicateMode,
        ]);

        return $counts;
    }

    /** @return list<array<string, string>> */
    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(__('Could not read the uploaded file.'));
        }

        // UTF-8 BOM tolerance.
        $first = fread($handle, 3);

        if ($first !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $delimiter = $this->sniffDelimiter($handle);
        $header = null;
        $rows = [];

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($cell): string => mb_strtolower(trim((string) $cell)), $data);

                continue;
            }

            // Same limit as spreadsheets (header + 5,000 data rows), so a large CSV
            // can't build an unbounded preview (RV-LAR-17).
            if (count($rows) + 1 >= UserSpreadsheetImportGuard::MAX_ROWS) {
                fclose($handle);

                throw new RuntimeException(__('This spreadsheet cannot be imported.'));
            }

            $rows[] = $this->combine($header, $data);
        }

        fclose($handle);

        return $rows;
    }

    /** @return list<array<string, string>> */
    private function spreadsheetRows(string $path): array
    {
        $this->spreadsheetGuard->assertSafe($path);

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new UserImportReadFilter);

        $spreadsheet = null;
        try {
            $spreadsheet = $this->loadSpreadsheet($reader, $path);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = min((int) $sheet->getHighestDataRow(), UserSpreadsheetImportGuard::MAX_ROWS);
            if ($highestRow < 1) {
                return [];
            }

            $header = [];
            $rows = [];
            for ($row = 1; $row <= $highestRow; $row++) {
                $data = [];
                foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $column) {
                    $data[] = $sheet->getCell($column.$row)->getValue();
                }

                if ($row === 1) {
                    $header = array_map(fn ($cell): string => mb_strtolower(trim((string) $cell)), $data);

                    continue;
                }

                $rows[] = $this->combine($header, $data);
            }

            return $rows;
        } finally {
            $spreadsheet?->disconnectWorksheets();
        }
    }

    /**
     * Test seam: overridden to prove rejected files never reach Reader::load().
     */
    protected function loadSpreadsheet(IReader $reader, string $path): Spreadsheet
    {
        return $reader->load($path);
    }

    /**
     * @param  list<string>  $header
     * @param  list<mixed>  $data
     * @return array<string, string>
     */
    private function combine(array $header, array $data): array
    {
        $row = [];

        foreach (self::HEADER as $position => $key) {
            $found = null;

            foreach ($header as $hIndex => $hKey) {
                if (str_contains($hKey, $key)) {
                    $found = $hIndex;

                    break;
                }
            }

            $row[$key] = $found !== null && isset($data[$found]) ? trim((string) $data[$found]) : '';
        }

        return $row;
    }

    /** Pick comma vs semicolon vs tab by counting occurrences of the header line. */
    private function sniffDelimiter($handle): string
    {
        $position = ftell($handle);
        $sample = (string) fgets($handle, 4096);
        fseek($handle, $position);

        $candidates = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t")];
        arsort($candidates);

        return (string) array_key_first($candidates);
    }
}
