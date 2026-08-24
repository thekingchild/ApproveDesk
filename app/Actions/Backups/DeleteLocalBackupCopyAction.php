<?php

namespace App\Actions\Backups;

use App\Enums\BackupDestinationKind;
use App\Models\BackupSnapshot;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class DeleteLocalBackupCopyAction
{
    public function execute(BackupSnapshot $snapshot): void
    {
        $snapshot->loadMissing('destination');

        if ($snapshot->destination?->kind !== BackupDestinationKind::Local) {
            throw new InvalidArgumentException('Only a local snapshot can drop its disk copy.');
        }

        if (! $this->hasRemoteReplica($snapshot)) {
            throw new InvalidArgumentException('Keep a remote copy before removing the local file.');
        }

        $path = $snapshot->path;
        $snapshot->delete();

        if (is_string($path) && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    public function executeEligible(): int
    {
        $removed = 0;

        $locals = BackupSnapshot::query()
            ->with('destination')
            ->whereHas('destination', fn ($query) => $query->where('kind', BackupDestinationKind::Local))
            ->get();

        foreach ($locals as $snapshot) {
            if (! $this->hasRemoteReplica($snapshot)) {
                continue;
            }

            $this->execute($snapshot);
            $removed++;
        }

        return $removed;
    }

    private function hasRemoteReplica(BackupSnapshot $snapshot): bool
    {
        return BackupSnapshot::query()
            ->where('path', $snapshot->path)
            ->where('id', '!=', $snapshot->id)
            ->successful()
            ->whereHas('destination', fn ($query) => $query->where('kind', '!=', BackupDestinationKind::Local))
            ->exists();
    }
}
