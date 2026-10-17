<?php

namespace App\Actions\Backups;

use App\Enums\BackupDestinationKind;
use App\Models\BackupSnapshot;
use App\Services\Backups\BackupInventory;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class DeleteLocalBackupCopyAction
{
    public function __construct(private readonly BackupInventory $inventory) {}

    public function execute(BackupSnapshot $snapshot): void
    {
        $snapshot->loadMissing('destination');

        if ($snapshot->destination?->kind !== BackupDestinationKind::Local) {
            throw new InvalidArgumentException('Only a local snapshot can drop its disk copy.');
        }

        if (! $this->inventory->hasRemoteReplica($snapshot)) {
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
            if (! $this->inventory->hasRemoteReplica($snapshot)) {
                continue;
            }

            $this->execute($snapshot);
            $removed++;
        }

        return $removed;
    }
}
