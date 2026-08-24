<?php

namespace App\Actions\Backups;

use App\Enums\BackupDestinationKind;
use App\Models\BackupSnapshot;
use App\Services\Backups\BackupTransportFactory;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class MaterializeBackupSnapshotAction
{
    public function __construct(private readonly BackupTransportFactory $transports) {}

    public function execute(BackupSnapshot $snapshot): string
    {
        $relative = $snapshot->path;

        if (is_string($relative) && Storage::disk('local')->exists($relative)) {
            return Storage::disk('local')->path($relative);
        }

        $snapshot->loadMissing('destination');

        if (
            is_string($relative)
            && is_string($snapshot->remote_id)
            && $snapshot->destination?->kind !== BackupDestinationKind::Local
        ) {
            Storage::disk('local')->makeDirectory(dirname($relative));
            $absolute = Storage::disk('local')->path($relative);
            $this->transports->make($snapshot->destination)->fetch($snapshot->remote_id, $absolute);

            if (! is_file($absolute) || filesize($absolute) === 0) {
                throw new InvalidArgumentException('The snapshot could not be fetched from the remote destination.');
            }

            return $absolute;
        }

        throw new InvalidArgumentException('The snapshot file is not on this copy.');
    }
}
