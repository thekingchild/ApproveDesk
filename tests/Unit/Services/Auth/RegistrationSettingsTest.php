<?php

namespace Tests\Unit\Services\Auth;

use App\Models\Setting;
use App\Support\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_allow_list_matching(): void
    {
        Setting::put('auth.allowed_registration_domains', "BlueChipAnalytical.com\nmail.corp.example");

        $this->assertTrue(RegistrationSettings::domainAllowed('femi@BLUECHIPANALYTICAL.com'));
        $this->assertTrue(RegistrationSettings::domainAllowed('a@mail.corp.example'));
        // Subdomains match only when explicitly listed.
        $this->assertFalse(RegistrationSettings::domainAllowed('x@sub.bluechipanalytical.com'));
        $this->assertFalse(RegistrationSettings::domainAllowed('someone@gmail.com'));
        $this->assertFalse(RegistrationSettings::domainAllowed('no-at-sign'));
    }

    public function test_empty_allow_list_allows_any_domain_and_defaults_to_otp_mode(): void
    {
        $this->assertSame([], RegistrationSettings::allowedDomains());
        $this->assertTrue(RegistrationSettings::domainAllowed('anyone@gmail.com'));
        $this->assertTrue(RegistrationSettings::requiresOtp());
    }
}
