<?php

namespace App\DTOs;

use Illuminate\Http\UploadedFile;

final readonly class BrandingSettingsData
{
    public function __construct(
        public string $tagline,
        public string $accentColor,
        public int $overlay,
        public ?string $customCss,
        public bool $allowRegistration,
        public bool $landingPageEnabled = false,
        public string $registrationMode = 'otp',
        public string $allowedRegistrationDomains = '',
        public string $metaTitle = '',
        public string $metaDescription = '',
        public ?UploadedFile $background = null,
        public bool $removeBackground = false,
        public ?UploadedFile $logo = null,
        public bool $removeLogo = false,
        public ?UploadedFile $favicon = null,
        public bool $removeFavicon = false,
        public ?string $customCode = null,
        /** Null leaves the stored list unchanged. */
        public ?string $customCodeOrigins = null,
    ) {}
}
