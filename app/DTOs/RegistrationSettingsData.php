<?php

namespace App\DTOs;

final readonly class RegistrationSettingsData
{
    public function __construct(
        public bool $allowRegistration,
        public bool $landingPageEnabled = false,
        public string $registrationMode = 'otp',
        public string $allowedRegistrationDomains = '',
    ) {}
}
