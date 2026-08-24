<?php

namespace App\Services\Backups;

use App\Enums\BackupDestinationKind;
use App\Models\BackupDestination;
use RuntimeException;

class BackupTransportFactory
{
    public function make(BackupDestination $destination): BackupTransport
    {
        if (! $destination->isConfigured()) {
            throw new RuntimeException('Destination is not configured.');
        }

        return match ($destination->kind) {
            BackupDestinationKind::Local => new LocalBackupTransport,
            BackupDestinationKind::S3 => new S3BackupTransport($destination),
            BackupDestinationKind::GoogleDrive => new GoogleDriveBackupTransport($destination),
        };
    }
}
