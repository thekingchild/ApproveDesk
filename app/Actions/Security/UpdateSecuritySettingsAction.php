<?php

namespace App\Actions\Security;

use App\Models\Setting;
use App\Models\User;
use App\Support\RateLimitProfiles;
use InvalidArgumentException;

final class UpdateSecuritySettingsAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input, ?User $actor = null): void
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

        if (array_key_exists('two_factor_required', $input)) {
            $required = ! empty($input['two_factor_required']);

            if ($required && ($actor === null || ! $actor->hasSatisfiedSecondFactor())) {
                throw new InvalidArgumentException('Turn on a second factor for your own account first.');
            }

            Setting::put('auth.two_factor_required', $required ? '1' : '0');
        }
    }
}
