<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Cache\RateLimiting\Limit;

final class RateLimitProfiles
{
    /** @var array<string, int> */
    public const DEFAULTS = [
        'login' => 5,
        'api' => 60,
        'approval_links' => 10,
        'exports' => 10,
        'webhooks' => 60,
        'integrations' => 60,
    ];

    public static function perMinute(string $profile): int
    {
        $default = self::DEFAULTS[$profile] ?? 60;
        $raw = Setting::get('rate_limit.'.$profile);
        $value = $raw === null || $raw === '' ? $default : (int) $raw;

        return max(1, min(1000, $value));
    }

    public static function limit(string $profile, string $by): Limit
    {
        return Limit::perMinute(self::perMinute($profile))->by($by);
    }

    /**
     * @return array<string, int>
     */
    public static function all(): array
    {
        $profiles = [];

        foreach (array_keys(self::DEFAULTS) as $profile) {
            $profiles[$profile] = self::perMinute($profile);
        }

        return $profiles;
    }
}
