<?php

namespace App\Actions\Backups;

use App\DTOs\BackupSettingsData;
use App\Models\Setting;
use App\Support\BackupSettings;

final class UpdateBackupSettingsAction
{
    public function execute(BackupSettingsData $data): void
    {
        Setting::put('backup.database_enabled', $data->databaseEnabled ? '1' : '0');
        Setting::put('backup.files_enabled', $data->filesEnabled ? '1' : '0');
        Setting::put('backup.database_retain_days', (string) BackupSettings::clampDays($data->databaseRetainDays));
        Setting::put('backup.files_retain_days', (string) BackupSettings::clampDays($data->filesRetainDays));
        Setting::put('backup.database_hour', $this->clock($data->databaseHour, '02:30'));
        Setting::put('backup.files_hour', $this->clock($data->filesHour, '02:45'));
    }

    private function clock(string $value, string $fallback): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $value) === 1 ? $value : $fallback;
    }
}
