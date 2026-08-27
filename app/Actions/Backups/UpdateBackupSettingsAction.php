<?php

namespace App\Actions\Backups;

use App\Models\Setting;
use App\Support\BackupSettings;

final class UpdateBackupSettingsAction
{
    public function __construct(private readonly RetainBackupSnapshotsAction $retain) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input): void
    {
        Setting::put('backup.database_enabled', ! empty($input['database_enabled']) ? '1' : '0');
        Setting::put('backup.files_enabled', ! empty($input['files_enabled']) ? '1' : '0');
        Setting::put('backup.database_retain_days', (string) BackupSettings::clampDays((int) ($input['database_retain_days'] ?? 14)));
        Setting::put('backup.files_retain_days', (string) BackupSettings::clampDays((int) ($input['files_retain_days'] ?? 7)));
        Setting::put('backup.database_hour', $this->clock($input['database_hour'] ?? '02:30', '02:30'));
        Setting::put('backup.files_hour', $this->clock($input['files_hour'] ?? '02:45', '02:45'));

        $this->retain->execute();
    }

    private function clock(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) === 1 ? $value : $fallback;
    }
}
