<?php

namespace App\Enums;

enum BackupDestinationKind: string
{
    case Local = 'local';
    case S3 = 's3';
    case GoogleDrive = 'gdrive';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local disk',
            self::S3 => 'S3-compatible',
            self::GoogleDrive => 'Google Drive',
        };
    }
}
