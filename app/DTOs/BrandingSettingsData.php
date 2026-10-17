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
        public string $metaTitle = '',
        public string $metaDescription = '',
        public ?UploadedFile $background = null,
        public bool $removeBackground = false,
        public ?UploadedFile $logo = null,
        public bool $removeLogo = false,
        public ?UploadedFile $favicon = null,
        public bool $removeFavicon = false,
    ) {}
}
