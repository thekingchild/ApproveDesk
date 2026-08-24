<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

final class BackupSettings
{
    public static function databaseEnabled(): bool
    {
        return self::flag('backup.database_enabled', '1');
    }

    public static function filesEnabled(): bool
    {
        return self::flag('backup.files_enabled', '1');
    }

    public static function databaseRetainDays(): int
    {
        return self::clampDays((int) self::value('backup.database_retain_days', '14'));
    }

    public static function filesRetainDays(): int
    {
        return self::clampDays((int) self::value('backup.files_retain_days', '7'));
    }

    public static function databaseAt(): string
    {
        return self::clock(self::value('backup.database_hour', '02:30'), '02:30');
    }

    public static function filesAt(): string
    {
        return self::clock(self::value('backup.files_hour', '02:45'), '02:45');
    }

    public static function clampDays(int $days): int
    {
        return max(3, min(90, $days));
    }

    private static function flag(string $key, string $default): bool
    {
        return self::value($key, $default) === '1';
    }

    private static function value(string $key, string $default): string
    {
        if (! Schema::hasTable('settings')) {
            return $default;
        }

        return (string) (Setting::get($key, $default) ?? $default);
    }

    private static function clock(?string $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) === 1 ? $value : $fallback;
    }
}
