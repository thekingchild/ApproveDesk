<?php

use App\Models\User;
use App\Services\Users\UserImportService;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Import users')] class extends Component {
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $file = null;

    public string $duplicateMode = 'skip';

    /** @var list<array<string, mixed>> */
    public array $preview = [];

    public bool $analyzed = false;

    public ?array $result = null;

    public function mount(): void
    {
        $this->authorize('users.import');
    }

    public function updatedFile(): void
    {
        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);
    }

    /**
     * Dry-run: parse and validate without writing anything.
     */
    public function analyze(): void
    {
        $this->authorize('users.import');
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240']]);

        try {
            $service = app(UserImportService::class);
            $rows = $service->rows($this->file->getRealPath(), $this->file->extension());
            $this->preview = $service->analyze($rows, auth()->user());
            $this->analyzed = true;
            $this->result = null;
        } catch (\RuntimeException|\PhpOffice\PhpSpreadsheet\Exception $exception) {
            $this->addError('file', $exception->getMessage());
        }
    }

    public function counts(): array
    {
        return [
            'ready' => count(array_filter($this->preview, fn (array $row): bool => ($row['status'] ?? '') === 'ready')),
            'duplicates' => count(array_filter($this->preview, fn (array $row): bool => ($row['status'] ?? '') === 'duplicate')),
            'errors' => count(array_filter($this->preview, fn (array $row): bool => ($row['status'] ?? '') === 'error')),
        ];
    }

    /**
     * Write the ready rows. Duplicate rows follow the chosen mode.
     */
    public function commit(): void
    {
        $this->authorize('users.import');

        if (! $this->analyzed || $this->preview === []) {
            $this->addError('file', __('Analyze the file first.'));

            return;
        }

        $counts = app(UserImportService::class)->commit($this->preview, auth()->user(), $this->duplicateMode);
        $this->result = $counts;
        $this->reset('preview', 'analyzed', 'file');

        Flux::toast(__('Import finished: :created created, :skipped skipped, :updated updated.', $counts), variant: 'success');
    }

    /** Download a CSV error report for the last analysis. */
    public function downloadErrors(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = array_filter($this->preview, fn (array $row): bool => in_array($row['status'] ?? '', ['error', 'duplicate'], true));

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['line', 'name', 'email', 'status', 'message']);

            foreach ($rows as $row) {
                fputcsv($handle, [$row['line'] ?? '', $row['name'] ?? '', $row['email'] ?? '', $row['status'] ?? '', $row['message'] ?? '']);
            }

            fclose($handle);
        }, 'user-import-errors.csv', ['Content-Type' => 'text/csv']);
    }
}
?>

<x-pages::settings.layout :heading="__('Import users')" :subheading="__('Onboard many people at once from a spreadsheet')">
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="flex items-center gap-1">
            <x-ui.help-link article="users-and-roles" />
        </div>

        <flux:card>
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="md">{{ __('1. Get the template') }}</flux:heading>
                    <flux:button size="sm" icon="arrow-down-tray" href="{{ route('admin.users.import.template') }}" data-test="download-template">{{ __('Download template') }}</flux:button>
                </div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Columns: name, email, department, manager_email, phone, roles. Only the first two are required. Departments must already exist; managers must exist or appear earlier in the file.') }}</p>
            </div>
        </flux:card>

        <flux:card>
            <div class="space-y-4" data-test="import-upload">
                <flux:heading size="md">{{ __('2. Upload and preview') }}</flux:heading>

                <flux:input type="file" wire:model="file" accept=".csv,.xlsx,.xls,text/csv" />
                <flux:error name="file" />
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('CSV or Excel · max 10 MiB · at most 5,000 data rows · oversized or highly compressed workbooks are refused · nothing is written until you confirm the import below.') }}</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="duplicateMode" :label="__('If an email already exists')" dusk="duplicate-mode">
                        <flux:select.option value="skip">{{ __('Skip that row') }}</flux:select.option>
                        <flux:select.option value="update">{{ __('Update the existing account') }}</flux:select.option>
                    </flux:select>
                    <div class="flex items-end">
                        <flux:button wire:click="analyze" variant="primary" :disabled="! $file" dusk="analyze-import">{{ __('Preview import') }}</flux:button>
                    </div>
                </div>
            </div>
        </flux:card>

        @if ($analyzed)
            @php($counts = $this->counts())
            <flux:card>
                <div class="space-y-4" data-test="import-preview">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading size="md">{{ __('3. Review and import') }}</flux:heading>
                        <div class="flex gap-2 text-sm">
                            <flux:badge color="lime" data-test="count-ready">{{ $counts['ready'] }} {{ __('ready') }}</flux:badge>
                            <flux:badge color="amber" data-test="count-duplicates">{{ $counts['duplicates'] }} {{ __('existing') }}</flux:badge>
                            <flux:badge color="red" data-test="count-errors">{{ $counts['errors'] }} {{ __('errors') }}</flux:badge>
                        </div>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Line') }}</flux:table.column>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Email') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach (array_slice($preview, 0, 50) as $row)
                                <flux:table.row :key="'row-'.($row['line'] ?? 0)">
                                    <flux:table.cell>{{ $row['line'] ?? '' }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['name'] ?? '' }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['email'] ?? '' }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if (($row['status'] ?? '') === 'ready')
                                            <flux:badge color="lime">{{ __('Will create') }}</flux:badge>
                                        @elseif (($row['status'] ?? '') === 'duplicate')
                                            <flux:badge color="amber">{{ __('Exists — will be '.($duplicateMode === 'update' ? 'updated' : 'skipped')) }}</flux:badge>
                                            <p class="mt-1 text-xs text-zinc-500">{{ $row['message'] }}</p>
                                        @else
                                            <flux:badge color="red">{{ __('Problem') }}</flux:badge>
                                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $row['message'] }}</p>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    @if (count($preview) > 50)
                        <p class="text-xs text-zinc-500">{{ __('Showing the first 50 of :total rows.', ['total' => count($preview)]) }}</p>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        @if ($counts['errors'] + $counts['duplicates'] > 0)
                            <flux:button size="sm" variant="outline" wire:click="downloadErrors">{{ __('Download problem report') }}</flux:button>
                        @endif
                        <flux:button variant="primary" wire:click="commit" wire:confirm="{{ __('Create the listed users? This cannot be undone.') }}" dusk="commit-import" data-test="commit-import">
                            {{ __('Import :count users', ['count' => $counts['ready']]) }}
                        </flux:button>
                    </div>
                </div>
            </flux:card>
        @endif

        @if ($result !== null)
            <flux:card data-test="import-result">
                <flux:heading size="md">{{ __('Import finished') }}</flux:heading>
                <p class="mt-2 text-sm">
                    {{ __(':created accounts created, :skipped duplicates skipped, :updated accounts updated.', $result) }}
                </p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('New accounts get a generated password — ask people to use “Forgot password” on the login screen to set their own.') }}</p>
            </flux:card>
        @endif
    </div>
</x-pages::settings.layout>
