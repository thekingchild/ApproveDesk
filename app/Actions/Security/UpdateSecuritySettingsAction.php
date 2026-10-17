<?php

namespace App\Actions\Security;

use App\DTOs\SecuritySettingsData;
use App\Models\Setting;
use App\Models\User;
use App\Support\RateLimitProfiles;
use InvalidArgumentException;

final class UpdateSecuritySettingsAction
{
    public function execute(SecuritySettingsData $data, ?User $actor = null): void
    {
        Setting::put('privacy.watermark_downloads', $data->watermarkDownloads ? '1' : '0');

        foreach (RateLimitProfiles::DEFAULTS as $profile => $default) {
            $value = $data->rateLimits[$profile] ?? $default;
            Setting::put('rate_limit.'.$profile, (string) max(1, min(1000, $value)));
        }

        if ($data->twoFactorRequired !== null) {
            $required = $data->twoFactorRequired;

            if ($required && ($actor === null || ! $actor->hasSatisfiedSecondFactor())) {
                throw new InvalidArgumentException('Turn on a second factor for your own account first.');
            }

            Setting::put('auth.two_factor_required', $required ? '1' : '0');
        }
    }
}
