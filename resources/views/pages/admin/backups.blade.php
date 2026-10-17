<?php

use App\Actions\Backups\CreateDatabaseBackupAction;
use App\Actions\Backups\CreateFilesBackupAction;
use App\Actions\Backups\DeleteLocalBackupCopyAction;
use App\Actions\Backups\PromoteDatabaseBackupAction;
use App\Actions\Backups\PromoteFilesBackupAction;
use App\Actions\Backups\UpdateBackupSettingsAction;
use App\Actions\Backups\VerifyBackupSnapshotAction;
use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupDestination;
use App\Models\BackupRestoreEvent;
use App\Models\BackupSnapshot;
use App\Services\Backups\BackupRecorder;
use App\Support\BackupSettings;
use App\Support\ScheduledBackupHealth;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Backups')] class extends Component
{
    public bool $databaseEnabled = true;

    public bool $filesEnabled = true;

    public int $databaseRetainDays = 14;

    public int $filesRetainDays = 7;

    public string $databaseHour = '02:30';

    public string $filesHour = '02:45';

    public string $promoteConfirmation = '';

    public function mount(): void
    {
        $this->authorize('backups.view');
        app(BackupRecorder::class)->localDestination();
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

        Flux::toast(__('Backup settings saved.'), variant: 'success');
    }

    public function backupDatabase(CreateDatabaseBackupAction $action): void
    {
        $this->authorize('backups.create');

        try {
            $action->execute();
        } catch (\InvalidArgumentException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['backup' => $exception->getMessage()]);
        }

        unset($this->snapshots, $this->remotelyCopiedPaths, $this->workflowActionsAtSnapshot, $this->restoreEvents);
        Flux::toast(__('Database snapshot stored.'), variant: 'success');
    }

    public function backupFiles(CreateFilesBackupAction $action): void
    {
        $this->authorize('backups.create');

        try {
            $action->execute();
        } catch (\InvalidArgumentException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['backup' => $exception->getMessage()]);
        }

        unset($this->snapshots, $this->remotelyCopiedPaths, $this->workflowActionsAtSnapshot, $this->restoreEvents);
        Flux::toast(__('Files snapshot stored.'), variant: 'success');
    }

    public function verify(int $id, VerifyBackupSnapshotAction $action): void
    {
        $this->authorize('backups.verify');
        try {
            $action->execute(BackupSnapshot::query()->findOrFail($id));
        } catch (\InvalidArgumentException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['promoteConfirmation' => $exception->getMessage()]);
        }
        unset($this->snapshots, $this->remotelyCopiedPaths, $this->workflowActionsAtSnapshot, $this->restoreEvents);
        Flux::toast(__('Snapshot checked out.'), variant: 'success');
    }

    public function promote(int $id, PromoteDatabaseBackupAction $database, PromoteFilesBackupAction $files): void
    {
        $this->authorize('backups.promote');
        $snapshot = BackupSnapshot::query()->findOrFail($id);

        try {
            if ($snapshot->kind === BackupSnapshotKind::Database) {
                $database->execute($snapshot, Auth::user(), $this->promoteConfirmation);
            } else {
                $files->execute($snapshot, Auth::user(), $this->promoteConfirmation);
            }
        } catch (\InvalidArgumentException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['promoteConfirmation' => $exception->getMessage()]);
        }

        $this->promoteConfirmation = '';
        unset($this->snapshots, $this->remotelyCopiedPaths, $this->workflowActionsAtSnapshot, $this->restoreEvents);
        Flux::toast(__('Snapshot promoted.'), variant: 'success');
    }

    public function downloadUrl(BackupSnapshot $snapshot): string
    {
        $this->authorize('backups.download');

        return URL::temporarySignedRoute('admin.backups.download', now()->addMinutes(5), $snapshot);
    }

    public function deleteLocal(int $id, DeleteLocalBackupCopyAction $action): void
    {
        $this->authorize('backups.local_copies.remove');

        try {
            $action->execute(BackupSnapshot::query()->findOrFail($id));
        } catch (\InvalidArgumentException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['promoteConfirmation' => $exception->getMessage()]);
        }

        unset($this->snapshots, $this->remotelyCopiedPaths, $this->workflowActionsAtSnapshot, $this->restoreEvents);
        Flux::toast(__('Local copy removed. Remote copies were kept.'), variant: 'success');
    }

    public function deleteLocalCopies(DeleteLocalBackupCopyAction $action): void
    {
        $this->authorize('backups.local_copies.remove');
        $removed = $action->executeEligible();
        unset($this->snapshots, $this->remotelyCopiedPaths, $this->workflowActionsAtSnapshot, $this->restoreEvents);
        Flux::toast(
            $removed === 0
                ? __('No local copies had a remote replica.')
                : trans_choice(':count local copy removed|:count local copies removed', $removed, ['count' => $removed]),
            variant: 'success',
        );
    }

    public function formattedSize(?int $bytes): string
    {
        return BackupSnapshot::formatBytes($bytes);
    }

    public function databaseBytes(): int
    {
        return (int) BackupSnapshot::query()
            ->where('kind', BackupSnapshotKind::Database)
            ->successful()
            ->get(['path', 'bytes'])
            ->unique('path')
            ->sum('bytes');
    }

    public function filesBytes(): int
    {
        return (int) BackupSnapshot::query()
            ->where('kind', BackupSnapshotKind::Files)
            ->successful()
            ->get(['path', 'bytes'])
            ->unique('path')
            ->sum('bytes');
    }

    public function canRemoveLocal(BackupSnapshot $snapshot): bool
    {
        return $snapshot->destination?->kind->value === 'local'
            && in_array($snapshot->path, $this->remotelyCopiedPaths, true);
    }

    /**
     * Snapshot rows for the table only. The manifest is left out: a files
     * manifest lists every stored file with its checksum (RV-PERF-17).
     *
     * @return \Illuminate\Support\Collection<int, BackupSnapshot>
     */
    #[Computed]
    public function snapshots(): \Illuminate\Support\Collection
    {
        return BackupSnapshot::query()
            ->select(['id', 'kind', 'destination_id', 'status', 'path', 'bytes', 'verified_at', 'promoted_at', 'taken_at', 'created_at'])
            ->with('destination')
            ->latest('taken_at')
            ->latest('id')
            ->limit(40)
            ->get();
    }

    /**
     * Paths of listed snapshots that also have a successful copy on a remote
     * destination, found in one query instead of one per row.
     *
     * @return list<string>
     */
    #[Computed]
    public function remotelyCopiedPaths(): array
    {
        $paths = $this->snapshots->pluck('path')->filter()->unique()->values()->all();

        if ($paths === []) {
            return [];
        }

        return BackupSnapshot::query()
            ->whereIn('path', $paths)
            ->successful()
            ->whereHas('destination', fn ($query) => $query->where('kind', '!=', 'local'))
            ->distinct()
            ->pluck('path')
            ->map(fn ($path): string => (string) $path)
            ->values()
            ->all();
    }

    /**
     * Workflow action counts recorded in the listed database snapshots' small
     * manifests, keyed by snapshot id (RV-PERF-17).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function workflowActionsAtSnapshot(): array
    {
        $ids = $this->snapshots->where('kind', BackupSnapshotKind::Database)->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        return BackupSnapshot::query()
            ->whereIn('id', $ids)
            ->get(['id', 'manifest'])
            ->mapWithKeys(fn (BackupSnapshot $snapshot): array => [$snapshot->id => (int) ($snapshot->manifestArray()['workflow_actions'] ?? 0)])
            ->all();
    }

    /** Workflow actions recorded after a database snapshot; a promote removes them from the live history. */
    public function actionsLostBy(BackupSnapshot $snapshot): int
    {
        return max(0, $this->liveWorkflowActions() - ($this->workflowActionsAtSnapshot[$snapshot->id] ?? 0));
    }

    #[Computed]
    public function liveWorkflowActions(): int
    {
        return \App\Models\WorkflowAction::query()->count();
    }

    /** @return \Illuminate\Support\Collection<int, BackupRestoreEvent> */
    #[Computed]
    public function restoreEvents(): \Illuminate\Support\Collection
    {
        return BackupRestoreEvent::query()->latest('id')->limit(20)->get();
    }

    /** @return \Illuminate\Support\Collection<int, BackupDestination> */
    #[Computed]
    public function destinations(): \Illuminate\Support\Collection
    {
        return BackupDestination::query()->orderBy('id')->get();
    }

    public function failedCount(): int
    {
        return BackupSnapshot::query()->where('status', BackupSnapshotStatus::Failed)->count();
    }

    public function scheduleSummary(): array
    {
        $health = app(ScheduledBackupHealth::class);

        return [
            'timezone' => (string) config('app.timezone', 'UTC'),
            'database_next' => $health->nextRun($this->databaseHour),
            'files_next' => $health->nextRun($this->filesHour),
            'database_last' => $health->lastScheduledAt(BackupSnapshotKind::Database),
            'files_last' => $health->lastScheduledAt(BackupSnapshotKind::Files),
        ];
    }
}; ?>

<div>
    <x-ui.page-header :title="__('Backups')" :description="__('Separate database and file snapshots. Host backups stay recommended.')" help-article="backups" />

    @if (session('status'))
        <flux:text class="mt-4">{{ session('status') }}</flux:text>
    @endif

    <form wire:submit="saveSettings" class="mt-8 space-y-5">
        <flux:heading size="sm">{{ __('Schedule and retain') }}</flux:heading>
        <flux:text>{{ __('Database defaults to 14 daily copies at 02:30. Files default to 7 daily copies at 02:45. Expired copies are removed at 04:15, but the last successful copy of each kind is kept.') }}</flux:text>
        <flux:text data-test="backup-schedule-summary">
            {{ __('Times use :timezone. Next database run: :database. Next files run: :files. Retention cleanup: 04:15.', [
                'timezone' => $this->scheduleSummary()['timezone'],
                'database' => $this->scheduleSummary()['database_next']->format('D j M, H:i'),
                'files' => $this->scheduleSummary()['files_next']->format('D j M, H:i'),
            ]) }}
        </flux:text>
        <flux:text>
            {{ __('Last scheduled database backup: :database. Last scheduled files backup: :files. Backup now does not test the scheduler.', [
                'database' => $this->scheduleSummary()['database_last']?->diffForHumans() ?? __('Never'),
                'files' => $this->scheduleSummary()['files_last']?->diffForHumans() ?? __('Never'),
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

    @can('backups.create')
        <div class="mt-8 flex flex-wrap gap-3">
            <flux:button variant="primary" wire:click="backupDatabase" data-test="backup-database-now">{{ __('Backup database') }}</flux:button>
            <flux:button wire:click="backupFiles" data-test="backup-files-now">{{ __('Backup files') }}</flux:button>
        </div>
    @endcan
    <flux:error name="backup" />
    @if ($this->failedCount() > 0)
        <flux:text class="mt-3" data-test="backup-failed-count">{{ trans_choice(':count snapshot failed|:count snapshots failed', $this->failedCount(), ['count' => $this->failedCount()]) }}</flux:text>
    @endif

    <div class="mt-8">
        <flux:heading size="sm">{{ __('Destinations') }}</flux:heading>
        <flux:text class="mt-1">{{ __('The local disk is always used. S3-compatible storage and Google Drive are optional and configured separately. A remote that is missing its secret is skipped and does not fail the local copy.') }}</flux:text>
        <ul class="mt-4 space-y-2 text-sm">
            @foreach ($this->destinations as $destination)
                <li class="flex flex-wrap items-center gap-3" data-test="backup-destination">
                    <span>
                        <span class="font-medium">{{ $destination->name }}</span>
                        — {{ $destination->kind->label() }}
                        ({{ $destination->enabled ? __('on') : __('off') }})
                        @if ($destination->last_error)
                            <span class="text-red-600">{{ $destination->last_error }}</span>
                        @endif
                    </span>
                    @if ($destination->kind->value !== 'local')
                        <form method="POST" action="{{ route('admin.backups.destinations.test', $destination) }}">
                            @csrf
                            <flux:button type="submit" size="xs">{{ __('Test') }}</flux:button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    @can('backups.destinations.manage')
        <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <flux:card data-test="backup-s3-card">
            <flux:heading size="sm">{{ __('S3-compatible') }}</flux:heading>
            <flux:text class="mt-1">{{ __('R2, Wasabi, MinIO, Spaces, or any S3-compatible bucket. Used only for snapshots, not for request integrations.') }}</flux:text>
            <form method="POST" action="{{ route('admin.backups.destinations.store') }}" class="mt-5 space-y-4">
                @csrf
                <input type="hidden" name="kind" value="s3">
                <input type="hidden" name="enabled" value="1">
                <flux:input name="name" :label="__('Name')" :value="old('name', __('Object storage'))" required />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input name="key" :label="__('Access key')" autocomplete="off" />
                    <flux:input name="secret" type="password" :label="__('Secret')" autocomplete="off" />
                    <flux:input name="region" :label="__('Region')" />
                    <flux:input name="bucket" :label="__('Bucket')" />
                    <flux:input name="endpoint" :label="__('Endpoint')" />
                    <flux:input name="prefix" :label="__('Prefix')" />
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="path_style" value="1">
                    {{ __('Path-style endpoint') }}
                </label>
                <flux:button type="submit" variant="primary" data-test="save-backup-destination">{{ __('Save S3 destination') }}</flux:button>
            </form>
        </flux:card>

        <flux:card data-test="backup-gdrive-card">
            <flux:heading size="sm">{{ __('Google Drive') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Unattended copies into a Drive folder. This is not the request integration connector. Save a refresh token so the schedule can run without a browser.') }}</flux:text>
            <form method="POST" action="{{ route('admin.backups.destinations.store') }}" class="mt-5 space-y-4">
                @csrf
                <input type="hidden" name="kind" value="gdrive">
                <input type="hidden" name="enabled" value="1">
                <flux:input name="name" :label="__('Name')" :value="__('Google Drive')" required />
                <flux:input name="folder_id" :label="__('Folder id')" />
                <flux:input name="client_id" :label="__('Client id')" autocomplete="off" />
                <flux:input name="client_secret" type="password" :label="__('Client secret')" autocomplete="off" />
                <flux:input name="refresh_token" type="password" :label="__('Refresh token')" autocomplete="off" />
                <flux:button type="submit" variant="primary" data-test="save-backup-gdrive">{{ __('Save Drive destination') }}</flux:button>
            </form>
        </flux:card>
    </div>
    @endcan

    <div class="mt-8">
    <flux:heading size="sm">{{ __('Snapshots') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Verify a snapshot before you promote it. Promoting a database snapshot replaces this copy’s database. Type the snapshot number to confirm.') }}</flux:text>
        <flux:text class="mt-2" data-test="backup-size-summary">
            {{ __('Database') }}: {{ $this->formattedSize($this->databaseBytes()) }}
            ·
            {{ __('Files') }}: {{ $this->formattedSize($this->filesBytes()) }}
        </flux:text>
        @can('backups.local_copies.remove')
            <div class="mt-3">
                <flux:button size="sm" wire:click="deleteLocalCopies" wire:confirm="{{ __('Remove local copies that already have a remote replica? Remote copies stay.') }}" data-test="delete-local-copies">
                    {{ __('Remove local copies (keep remotes)') }}
                </flux:button>
            </div>
        @endcan
        @can('backups.promote')
            <flux:input class="mt-4 max-w-xs" wire:model="promoteConfirmation" :label="__('Snapshot number')" data-test="promote-confirmation" />
            <flux:error name="promoteConfirmation" />
        @endcan
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-zinc-500">
                        <th class="py-2 pr-3">{{ __('Date and time') }}</th>
                        <th class="py-2 pr-3">{{ __('Id') }}</th>
                        <th class="py-2 pr-3">{{ __('Kind') }}</th>
                        <th class="py-2 pr-3">{{ __('Where') }}</th>
                        <th class="py-2 pr-3">{{ __('Size') }}</th>
                        <th class="py-2 pr-3">{{ __('Status') }}</th>
                        <th class="py-2">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->snapshots as $snapshot)
                        <tr class="border-t border-zinc-200 dark:border-zinc-800" data-test="backup-snapshot">
                            <td class="py-2 pr-3 font-mono" data-test="backup-snapshot-taken">{{ $snapshot->displayName() }}</td>
                            <td class="py-2 pr-3 font-mono">{{ $snapshot->id }}</td>
                            <td class="py-2 pr-3">{{ $snapshot->kind->value }}</td>
                            <td class="py-2 pr-3">{{ $snapshot->destination?->name }}</td>
                            <td class="py-2 pr-3" data-test="backup-snapshot-size">{{ $this->formattedSize($snapshot->bytes) }}</td>
                            <td class="py-2 pr-3">{{ $snapshot->status->value }}</td>
                            <td class="py-2">
                                <div class="flex flex-wrap gap-2">
                                    @can('backups.verify')
                                        <flux:button size="xs" wire:click="verify({{ $snapshot->id }})">{{ __('Verify') }}</flux:button>
                                    @endcan
                                    @can('backups.download')
                                        <flux:button size="xs" :href="$this->downloadUrl($snapshot)">{{ __('Download') }}</flux:button>
                                    @endcan
                                    @can('backups.promote')
                                        @if ($snapshot->kind === \App\Enums\BackupSnapshotKind::Database)
                                            <flux:button size="xs" variant="danger" wire:click="promote({{ $snapshot->id }})" wire:confirm="{{ __('Replace this database with snapshot :id? :count workflow actions recorded after it will be removed from the live history. A safety backup of the current database is taken first, and the site stays in maintenance mode until the restore is checked.', ['id' => $snapshot->id, 'count' => $this->actionsLostBy($snapshot)]) }}" data-test="promote-snapshot-{{ $snapshot->id }}">{{ __('Promote') }}</flux:button>
                                        @else
                                            <flux:button size="xs" variant="danger" wire:click="promote({{ $snapshot->id }})" data-test="promote-snapshot-{{ $snapshot->id }}">{{ __('Promote') }}</flux:button>
                                        @endif
                                    @endcan
                                    @can('backups.local_copies.remove')
                                        @if ($this->canRemoveLocal($snapshot))
                                            <flux:button size="xs" variant="ghost" wire:click="deleteLocal({{ $snapshot->id }})" wire:confirm="{{ __('Remove the local file? Remote copies stay.') }}" data-test="delete-local-{{ $snapshot->id }}">{{ __('Remove local') }}</flux:button>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-4 text-zinc-500">{{ __('No snapshots yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-8" data-test="backup-restore-trail">
        <flux:heading size="sm">{{ __('Restore history') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Each promote is recorded here: who restored which snapshot, and when. Database restores keep this trail on the restored copy.') }}</flux:text>
        <ul class="mt-4 space-y-2 text-sm">
            @forelse ($this->restoreEvents as $event)
                <li data-test="backup-restore-event">
                    {{ $event->created_at?->timezone((string) config('app.timezone', 'UTC'))->format('D j M Y, H:i') }}
                    —
                    {{ $event->kind->value }}
                    {{ $event->snapshot_name }}
                    {{ __('restored by :name', ['name' => $event->actor_name]) }}
                    @if ($event->safety_snapshot_id)
                        · {{ __('safety backup :id', ['id' => $event->safety_snapshot_id]) }}
                    @endif
                    @if ($event->discarded_workflow_actions)
                        · <span class="font-medium text-amber-700 dark:text-amber-400" data-test="restore-discarded">{{ trans_choice(':count later workflow action removed|:count later workflow actions removed', $event->discarded_workflow_actions) }}</span>
                    @endif
                </li>
            @empty
                <li class="text-zinc-500">{{ __('No snapshots have been restored yet.') }}</li>
            @endforelse
        </ul>
    </div>
</div>
