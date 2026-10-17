<?php

namespace App\Actions\Backups;

use App\Enums\BackupDestinationKind;
use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupSnapshot;
use App\Services\Backups\BackupTransportFactory;
use App\Support\BackupSettings;
use Illuminate\Support\Facades\Storage;

final class RetainBackupSnapshotsAction
{
    public function __construct(private readonly BackupTransportFactory $transports) {}

    public function execute(): int
    {
        $deleted = 0;
        $deleted += $this->prune(BackupSnapshotKind::Database, BackupSettings::databaseRetainDays());
        $deleted += $this->prune(BackupSnapshotKind::Files, BackupSettings::filesRetainDays());

        return $deleted;
    }

    private function prune(BackupSnapshotKind $kind, int $days): int
    {
        $keep = BackupSnapshot::query()
            ->where('kind', $kind)
            ->successful()
            ->latest('id')
            ->first();

        $expired = BackupSnapshot::query()
            ->where('kind', $kind)
            ->where('retained_until', '<', now())
            ->where('status', '!=', BackupSnapshotStatus::Failed)
            ->get();

        $deleted = 0;
        foreach ($expired as $snapshot) {
            if ($keep instanceof BackupSnapshot && $snapshot->id === $keep->id) {
                continue;
            }

            $this->forgetFile($snapshot);
            $snapshot->delete();
            $deleted++;
        }

        return $deleted;
    }

    private function forgetFile(BackupSnapshot $snapshot): void
    {
        if (is_string($snapshot->path) && Storage::disk('local')->exists($snapshot->path)) {
            $stillUsed = BackupSnapshot::query()
                ->where('path', $snapshot->path)
                ->where('id', '!=', $snapshot->id)
                ->exists();
            if (! $stillUsed) {
                Storage::disk('local')->delete($snapshot->path);
            }
        }

        if (is_string($snapshot->remote_id) && $snapshot->destination?->kind !== BackupDestinationKind::Local) {
            try {
                $this->transports->make($snapshot->destination)->delete($snapshot->remote_id);
            } catch (\Throwable) {
                // Keep pruning the row even if the remote object is already gone.
            }
        }
    }
}
