<?php

namespace App\Actions\Security;

use App\Models\Setting;
use App\Support\RateLimitProfiles;

final class UpdateSecuritySettingsAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input): void
    {
        Setting::put(
            'privacy.watermark_downloads',
            ! empty($input['watermark_downloads']) ? '1' : '0',
        );

        foreach (array_keys(RateLimitProfiles::DEFAULTS) as $profile) {
            $key = 'rate_limit_'.$profile;
            $value = isset($input[$key]) ? (int) $input[$key] : RateLimitProfiles::DEFAULTS[$profile];
            Setting::put('rate_limit.'.$profile, (string) max(1, min(1000, $value)));
        }
    }
}
