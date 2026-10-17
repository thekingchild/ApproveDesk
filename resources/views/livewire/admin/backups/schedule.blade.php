<?php

use App\Actions\Backups\UpdateBackupSettingsAction;
use App\Enums\BackupSnapshotKind;
use App\Services\Backups\ScheduledBackupHealth;
use App\Support\BackupSettings;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * Backup schedule and retention on the Backups page (ADR 0006 split, RV-ARC-09).
 */
new class extends Component {
    public bool $databaseEnabled = true;

    public bool $filesEnabled = true;

    public int $databaseRetainDays = 14;

    public int $filesRetainDays = 7;

    public string $databaseHour = '02:30';

    public string $filesHour = '02:45';

    public function mount(): void
    {
        $this->authorize('backups.view');
        $this->databaseEnabled = BackupSettings::databaseEnabled();
        $this->filesEnabled = BackupSettings::filesEnabled();
        $this->databaseRetainDays = BackupSettings::databaseRetainDays();
        $this->filesRetainDays = BackupSettings::filesRetainDays();
        $this->databaseHour = BackupSettings::databaseAt();
        $this->filesHour = BackupSettings::filesAt();
    }

    public function saveSettings(UpdateBackupSettingsAction $action): void
    {
        $this->authorize('backups.configure');
        $validated = $this->validate([
            'databaseEnabled' => ['boolean'],
            'filesEnabled' => ['boolean'],
            'databaseRetainDays' => ['required', 'integer', 'min:3', 'max:90'],
            'filesRetainDays' => ['required', 'integer', 'min:3', 'max:90'],
            'databaseHour' => ['required', 'date_format:H:i'],
            'filesHour' => ['required', 'date_format:H:i'],
        ]);

        $action->execute([
            'database_enabled' => $validated['databaseEnabled'],
            'files_enabled' => $validated['filesEnabled'],
            'database_retain_days' => $validated['databaseRetainDays'],
            'files_retain_days' => $validated['filesRetainDays'],
            'database_hour' => $validated['databaseHour'],
            'files_hour' => $validated['filesHour'],
        ]);

        unset($this->summary);
        Flux::toast(__('Backup settings saved.'), variant: 'success');
    }

    /** @return array{timezone: string, database_next: \Carbon\CarbonInterface, files_next: \Carbon\CarbonInterface, database_last: ?\Carbon\CarbonInterface, files_last: ?\Carbon\CarbonInterface} */
    #[Computed]
    public function summary(): array
    {
        $health = app(ScheduledBackupHealth::class);

        return [
            'timezone' => (string) config('app.timezone', 'UTC'),
            'database_next' => $health->nextRun(BackupSettings::databaseAt()),
            'files_next' => $health->nextRun(BackupSettings::filesAt()),
            'database_last' => $health->lastScheduledAt(BackupSnapshotKind::Database),
            'files_last' => $health->lastScheduledAt(BackupSnapshotKind::Files),
        ];
    }
} ?>

<form wire:submit="saveSettings" class="mt-8 space-y-5">
    <flux:heading size="sm">{{ __('Schedule and retain') }}</flux:heading>
    <flux:text>{{ __('Database defaults to 14 daily copies at 02:30. Files default to 7 daily copies at 02:45. Expired copies are removed at 04:15, but the last successful copy of each kind is kept.') }}</flux:text>
    <flux:text data-test="backup-schedule-summary">
        {{ __('Times use :timezone. Next database run: :database. Next files run: :files. Retention cleanup: 04:15.', [
            'timezone' => $this->summary['timezone'],
            'database' => $this->summary['database_next']->format('D j M, H:i'),
            'files' => $this->summary['files_next']->format('D j M, H:i'),
        ]) }}
    </flux:text>
    <flux:text>
        {{ __('Last scheduled database backup: :database. Last scheduled files backup: :files. Backup now does not test the scheduler.', [
            'database' => $this->summary['database_last']?->diffForHumans() ?? __('Never'),
            'files' => $this->summary['files_last']?->diffForHumans() ?? __('Never'),
        ]) }}
    </flux:text>
    <div class="grid gap-4 sm:grid-cols-2">
        <flux:checkbox wire:model="databaseEnabled" :label="__('Backup the database on a schedule')" data-test="backup-database-enabled" />
        <flux:checkbox wire:model="filesEnabled" :label="__('Backup files on a schedule')" />
        <flux:input wire:model="databaseRetainDays" type="number" min="3" max="90" :label="__('Keep database copies (days)')" />
        <flux:input wire:model="filesRetainDays" type="number" min="3" max="90" :label="__('Keep file copies (days)')" />
        <flux:input wire:model="databaseHour" type="time" step="60" :label="__('Database backup time')" data-test="backup-database-time" />
        <flux:input wire:model="filesHour" type="time" step="60" :label="__('Files backup time')" data-test="backup-files-time" />
    </div>
    @can('backups.configure')
        <flux:button type="submit" variant="primary" data-test="save-backup-settings">{{ __('Save schedule') }}</flux:button>
    @endcan
</form>
