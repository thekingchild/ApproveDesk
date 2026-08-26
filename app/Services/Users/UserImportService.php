<?php

namespace App\Services\Users;

use App\Models\Department;
use App\Models\User;
use App\Services\Roles\RoleAssignmentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * CSV/XLSX user import for large-organization onboarding (Phase UO.1).
 *
 * Pipeline: rows() -> analyze() [dry-run report] -> commit(). Rows carry
 * per-row status so one bad line never aborts the batch. Passwords are
 * generated (never imported) and emails are case-insensitively unique.
 */
final class UserImportService
{
    public const HEADER = ['name', 'email', 'department', 'manager_email', 'phone', 'roles'];

    private const CHUNK = 200;

    public function __construct(
        private readonly GrantableRoleService $roles,
        private readonly RoleAssignmentService $assignments,
        private readonly ActiveUserLimitService $limits,
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
        $pendingManagerEmails = [];
        $reports = [];

        foreach ($rows as $index => $row) {
            $report = $row;
            $line = $index + 2; // header is line 1
            $name = trim((string) $report['name']);
            $email = mb_strtolower(trim((string) $report['email']));
            $department = trim((string) ($report['department'] ?? ''));
            $managerEmail = mb_strtolower(trim((string) ($report['manager_email'] ?? '')));
            $phone = trim((string) ($report['phone'] ?? ''));
            $roles = collect(preg_split('/[;,]+/', (string) ($report['roles'] ?? '')) ?: [])
                ->map(fn (string $role): string => trim($role))
                ->filter()
                ->values()
                ->all();

            if ($name === '') {
                $report = [...$report, 'line' => $line, 'status' => 'error', 'message' => __('Name is required.')];

                $reports[] = $report;

                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $report = [...$report, 'line' => $line, 'status' => 'error', 'message' => __('A valid email is required.')];

                $reports[] = $report;

                continue;
            }

            if (in_array($email, $existingEmails, true)) {
                $report = [...$report, 'line' => $line, 'status' => 'duplicate', 'message' => __('An account with this email already exists.')];

                $reports[] = $report;

                continue;
            }

            if ($department !== '' && ! in_array(mb_strtolower($department), $departmentNames, true)) {
                $report = [...$report, 'line' => $line, 'status' => 'error', 'message' => __('Department ":department" does not exist.', ['department' => $department])];

                $reports[] = $report;

                continue;
            }

            foreach ($roles as $role) {
                if (! in_array($role, $this->roles->for($actor), true)) {
                    $reports[] = [...$report, 'line' => $line, 'status' => 'error', 'message' => __('Role ":role" cannot be granted by you or does not exist.', ['role' => $role])];

                    continue 2;
                }
            }

            if ($phone !== '' && preg_match('/^\+[1-9]\d{7,14}$/', $phone) !== 1) {
                $report = [...$report, 'line' => $line, 'status' => 'error', 'message' => __('Phone must be international format, e.g. +2348012345678.')];

                $reports[] = $report;

                continue;
            }

            // Manager may exist already or appear on an earlier valid row.
            if ($managerEmail !== ''
                && ! in_array($managerEmail, $existingEmails, true)
                && ! in_array($managerEmail, $pendingManagerEmails, true)
                && ! in_array($managerEmail, array_map(fn (array $r): string => mb_strtolower(trim((string) $r['email'])), array_slice($rows, 0, $index)), true)) {
                $report = [...$report, 'line' => $line, 'status' => 'error', 'message' => __('Manager :email is not an existing user and does not appear earlier in this file.', ['email' => $managerEmail])];

                $reports[] = $report;

                continue;
            }

            if ($managerEmail !== '') {
                $existingManager = User::query()->where('email', $managerEmail)->first();
                if ($existingManager instanceof User && $existingManager->isSystemOwner()) {
                    $reports[] = [...$report, 'line' => $line, 'status' => 'error', 'message' => __('That manager is not available for assignment.')];

                    continue;
                }

                $pendingManagerEmails[] = $managerEmail;
            }

            $reports[] = [...$report, 'line' => $line, 'status' => 'ready', 'message' => ''];
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

            $rows[] = $this->combine($header, $data);
        }

        fclose($handle);

        return $rows;
    }

    /** @return list<array<string, string>> */
    private function spreadsheetRows(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $matrix = $sheet->toArray(null, true, true, false);

        if ($matrix === []) {
            return [];
        }

        $header = array_map(fn ($cell): string => mb_strtolower(trim((string) $cell)), array_shift($matrix));

        return array_map(fn (array $data): array => $this->combine($header, $data), $matrix);
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
