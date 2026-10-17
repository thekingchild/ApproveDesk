<?php

namespace App\Support;

use App\Models\Setting;

/**
 * How long finished work and abandoned drafts are kept before they are
 * retired (ADR 0007: one reader owns these keys, defaults and bounds).
 */
final class RetentionSettings
{
    public const DRAFT_DAYS = 'retention.draft_days';

    public const COMPLETED_DAYS = 'retention.completed_days';

    public const DEFAULT_DRAFT_DAYS = 90;

    /** About seven years, a common records-keeping period. */
    public const DEFAULT_COMPLETED_DAYS = 2555;

    public const MAX_DRAFT_DAYS = 3650;

    public const MAX_COMPLETED_DAYS = 7300;

    public static function draftDays(): int
    {
        return self::clamp(Setting::get(self::DRAFT_DAYS), self::DEFAULT_DRAFT_DAYS, self::MAX_DRAFT_DAYS);
    }

    public static function completedDays(): int
    {
        return self::clamp(Setting::get(self::COMPLETED_DAYS), self::DEFAULT_COMPLETED_DAYS, self::MAX_COMPLETED_DAYS);
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'draftDays' => ['required', 'integer', 'min:1', 'max:'.self::MAX_DRAFT_DAYS],
            'completedDays' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COMPLETED_DAYS],
        ];
    }

    private static function clamp(mixed $stored, int $default, int $max): int
    {
        $days = is_numeric($stored) ? (int) $stored : $default;

        return min($max, max(1, $days));
    }
}
