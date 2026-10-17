<?php

namespace App\Actions\Settings;

use App\DTOs\RegistrationSettingsData;
use App\Models\Setting;
use App\Support\RegistrationSettings;

/** The public landing page switch and self-registration rules (Settings → Branding). */
final class UpdateRegistrationSettingsAction
{
    public function execute(RegistrationSettingsData $data): void
    {
        Setting::put('auth.allow_registration', $data->allowRegistration ? 'true' : 'false');
        Setting::put('brand.landing_page_enabled', $data->landingPageEnabled ? 'true' : 'false');
        Setting::put('auth.registration_mode', in_array($data->registrationMode, [RegistrationSettings::MODE_IMMEDIATE, RegistrationSettings::MODE_OTP], true)
            ? $data->registrationMode
            : RegistrationSettings::MODE_OTP);

        // Normalise the allow-list: lowercase, one per line, no blanks/dupes.
        $domains = collect(preg_split('/[\r\n,]+/', $data->allowedRegistrationDomains) ?: [])
            ->map(fn (string $domain): string => mb_strtolower(trim($domain)))
            ->filter()
            ->unique()
            ->implode("\n");
        Setting::put('auth.allowed_registration_domains', $domains === '' ? null : $domains);
    }
}
