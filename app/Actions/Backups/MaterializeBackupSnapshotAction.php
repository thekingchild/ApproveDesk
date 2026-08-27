<?php

namespace App\Actions\Backups;

use App\Enums\BackupDestinationKind;
use App\Models\BackupSnapshot;
use App\Services\Backups\BackupIntegrity;
use App\Services\Backups\BackupTransportFactory;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

final class MaterializeBackupSnapshotAction
{
    public function __construct(
        private readonly BackupTransportFactory $transports,
        private readonly BackupIntegrity $integrity,
    ) {}

    public function execute(BackupSnapshot $snapshot): string
    {
        $relative = $snapshot->path;

        if (is_string($relative) && Storage::disk('local')->exists($relative)) {
            $absolute = Storage::disk('local')->path($relative);
            $this->integrity->assertMatches($snapshot, $absolute);

            return $absolute;
        }

        $snapshot->loadMissing('destination');

        if (
            is_string($relative)
            && is_string($snapshot->remote_id)
            && $snapshot->destination?->kind !== BackupDestinationKind::Local
        ) {
            return $this->fetchRemote($snapshot, $relative);
        }

        throw new InvalidArgumentException('The snapshot file is not on this copy.');
    }

    private function fetchRemote(BackupSnapshot $snapshot, string $relative): string
    {
        $tempDir = storage_path('app/private/backups');
        if (! is_dir($tempDir) && ! mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            throw new InvalidArgumentException('The snapshot could not be fetched from the remote destination.');
        }

        $temp = $tempDir.'/.materialize-'.uniqid('', true);

        try {
            $this->transports->make($snapshot->destination)->fetch((string) $snapshot->remote_id, $temp);

            if (! is_file($temp) || filesize($temp) === 0) {
                throw new InvalidArgumentException('The snapshot could not be fetched from the remote destination.');
            }

            $this->integrity->assertMatches($snapshot, $temp);

            Storage::disk('local')->makeDirectory(dirname($relative));
            $absolute = Storage::disk('local')->path($relative);

            if (! @rename($temp, $absolute)) {
                if (! @copy($temp, $absolute)) {
                    throw new InvalidArgumentException('The snapshot could not be fetched from the remote destination.');
                }
                @unlink($temp);
            }

            return $absolute;
        } catch (Throwable $exception) {
            if (is_file($temp)) {
                @unlink($temp);
            }

            throw $exception;
        }
    }
}
