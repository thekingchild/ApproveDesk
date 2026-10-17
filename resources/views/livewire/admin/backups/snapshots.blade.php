<?php

use App\Actions\Backups\CreateDatabaseBackupAction;
use App\Actions\Backups\CreateFilesBackupAction;
use App\Actions\Backups\DeleteLocalBackupCopyAction;
use App\Actions\Backups\PromoteDatabaseBackupAction;
use App\Actions\Backups\PromoteFilesBackupAction;
use App\Actions\Backups\VerifyBackupSnapshotAction;
use App\Enums\BackupDestinationKind;
use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupSnapshot;
use App\Services\Backups\BackupInventory;
use App\Support\BackupDownloadLinks;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * Backup now, the snapshot table and its verify / download / promote / remove
 * local actions on the Backups page (ADR 0006 split, RV-ARC-09). Each change
 * dispatches `backups-changed` so the destinations and restore trail refresh.
 */
new class extends Component {
    public string $promoteConfirmation = '';

    public function mount(): void
    {
        $this->authorize('backups.view');
    }

    public function backupDatabase(CreateDatabaseBackupAction $action): void
    {
        $this->authorize('backups.create');
        $this->refusalAs('backup', fn () => $action->execute());
        $this->changed(__('Database snapshot stored.'));
    }

    public function backupFiles(CreateFilesBackupAction $action): void
    {
        $this->authorize('backups.create');
        $this->refusalAs('backup', fn () => $action->execute());
        $this->changed(__('Files snapshot stored.'));
    }

    public function verify(int $id, VerifyBackupSnapshotAction $action): void
    {
        $this->authorize('backups.verify');
        $this->refusalAs('promoteConfirmation', fn () => $action->execute(BackupSnapshot::query()->findOrFail($id)));
        $this->changed(__('Snapshot checked out.'));
    }

    public function promote(int $id, PromoteDatabaseBackupAction $database, PromoteFilesBackupAction $files): void
    {
        $this->authorize('backups.promote');
        $snapshot = BackupSnapshot::query()->findOrFail($id);

        $this->refusalAs('promoteConfirmation', fn () => $snapshot->kind === BackupSnapshotKind::Database
            ? $database->execute($snapshot, Auth::user(), $this->promoteConfirmation)
            : $files->execute($snapshot, Auth::user(), $this->promoteConfirmation));

        $this->promoteConfirmation = '';
        $this->changed(__('Snapshot promoted.'));
    }

    public function deleteLocal(int $id, DeleteLocalBackupCopyAction $action): void
    {
        $this->authorize('backups.local_copies.remove');
        $this->refusalAs('promoteConfirmation', fn () => $action->execute(BackupSnapshot::query()->findOrFail($id)));
        $this->changed(__('Local copy removed. Remote copies were kept.'));
    }

    public function deleteLocalCopies(DeleteLocalBackupCopyAction $action): void
    {
        $this->authorize('backups.local_copies.remove');
        $removed = $action->executeEligible();
        $this->changed($removed === 0
            ? __('No local copies had a remote replica.')
            : trans_choice(':count local copy removed|:count local copies removed', $removed, ['count' => $removed]));
    }

    /**
     * Snapshot rows for the table only. The manifest is left out: a files
     * manifest lists every stored file with its checksum (RV-PERF-17).
     *
     * @return Collection<int, BackupSnapshot>
     */
    #[Computed]
    public function snapshots(): Collection
    {
        return BackupSnapshot::query()
            ->select(['id', 'kind', 'destination_id', 'status', 'path', 'bytes', 'verified_at', 'promoted_at', 'taken_at', 'created_at'])
            ->with('destination')
            ->latest('taken_at')
            ->latest('id')
            ->limit(40)
            ->get()
            ->toBase();
    }

    /** @return array{database: int, files: int} */
    #[Computed]
    public function storedBytes(): array
    {
        $inventory = app(BackupInventory::class);

        return [
            'database' => $inventory->storedBytes(BackupSnapshotKind::Database),
            'files' => $inventory->storedBytes(BackupSnapshotKind::Files),
        ];
    }

    #[Computed]
    public function failedCount(): int
    {
        return BackupSnapshot::query()->where('status', BackupSnapshotStatus::Failed)->count();
    }

    /** @return list<string> */
    #[Computed]
    public function remotelyCopiedPaths(): array
    {
        return app(BackupInventory::class)->remotelyCopiedPaths($this->snapshots->pluck('path'));
    }

    /** @return array<int, int> */
    #[Computed]
    public function actionsLost(): array
    {
        return app(BackupInventory::class)->workflowActionsLostBy(
            $this->snapshots->where('kind', BackupSnapshotKind::Database)->pluck('id'),
        );
    }

    /** Not an endpoint (ADR 0006, RV-LAR-16): the download route re-checks the permission and the signature. */
    protected function downloadUrl(BackupSnapshot $snapshot): string
    {
        return BackupDownloadLinks::download($snapshot);
    }

    protected function canRemoveLocal(BackupSnapshot $snapshot): bool
    {
        return $snapshot->destination?->kind === BackupDestinationKind::Local
            && in_array($snapshot->path, $this->remotelyCopiedPaths, true);
    }

    /** Shows an Action's refusal under the given field instead of an error page. */
    private function refusalAs(string $field, Closure $run): void
    {
        try {
            $run();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    private function changed(string $message): void
    {
        unset($this->snapshots, $this->storedBytes, $this->failedCount, $this->remotelyCopiedPaths, $this->actionsLost);
        $this->dispatch('backups-changed');
        Flux::toast($message, variant: 'success');
    }
} ?>

<div>
    @can('backups.create')
        <div class="mt-8 flex flex-wrap gap-3">
            <flux:button variant="primary" wire:click="backupDatabase" data-test="backup-database-now">{{ __('Backup database') }}</flux:button>
            <flux:button wire:click="backupFiles" data-test="backup-files-now">{{ __('Backup files') }}</flux:button>
        </div>
    @endcan
    <flux:error name="backup" />
    @if ($this->failedCount > 0)
        <flux:text class="mt-3" data-test="backup-failed-count">{{ trans_choice(':count snapshot failed|:count snapshots failed', $this->failedCount, ['count' => $this->failedCount]) }}</flux:text>
    @endif

    <div class="mt-8">
        <flux:heading size="sm">{{ __('Snapshots') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Verify a snapshot before you promote it. Promoting a database snapshot replaces this copy’s database. Type the snapshot number to confirm.') }}</flux:text>
        <flux:text class="mt-2" data-test="backup-size-summary">
            {{ __('Database') }}: {{ BackupSnapshot::formatBytes($this->storedBytes['database']) }}
            ·
            {{ __('Files') }}: {{ BackupSnapshot::formatBytes($this->storedBytes['files']) }}
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
                        <tr class="border-t border-zinc-200 dark:border-zinc-800" wire:key="backup-snapshot-{{ $snapshot->id }}" data-test="backup-snapshot">
                            <td class="py-2 pr-3 font-mono" data-test="backup-snapshot-taken">{{ $snapshot->displayName() }}</td>
                            <td class="py-2 pr-3 font-mono">{{ $snapshot->id }}</td>
                            <td class="py-2 pr-3">{{ $snapshot->kind->value }}</td>
                            <td class="py-2 pr-3">{{ $snapshot->destination?->name }}</td>
                            <td class="py-2 pr-3" data-test="backup-snapshot-size">{{ BackupSnapshot::formatBytes($snapshot->bytes) }}</td>
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
                                        @if ($snapshot->kind === BackupSnapshotKind::Database)
                                            <flux:button size="xs" variant="danger" wire:click="promote({{ $snapshot->id }})" wire:confirm="{{ __('Replace this database with snapshot :id? :count workflow actions recorded after it will be removed from the live history. A safety backup of the current database is taken first, and the site stays in maintenance mode until the restore is checked.', ['id' => $snapshot->id, 'count' => $this->actionsLost[$snapshot->id] ?? 0]) }}" data-test="promote-snapshot-{{ $snapshot->id }}">{{ __('Promote') }}</flux:button>
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
</div>
