<?php

namespace App\DTOs;

/** The backup schedule on Administration → Backups (RV-ARC-13). Hours are HH:MM. */
final readonly class BackupSettingsData
{
    public function __construct(
        public bool $databaseEnabled,
        public bool $filesEnabled,
        public int $databaseRetainDays,
        public int $filesRetainDays,
        public string $databaseHour,
        public string $filesHour,
    ) {}
}
