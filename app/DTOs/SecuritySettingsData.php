<?php

namespace App\DTOs;

/** Settings → Security & privacy (RV-ARC-13). */
final readonly class SecuritySettingsData
{
    /**
     * @param  array<string, int>  $rateLimits  requests per minute, keyed by RateLimitProfiles profile
     * @param  bool|null  $twoFactorRequired  null leaves the requirement as it is
     */
    public function __construct(
        public bool $watermarkDownloads,
        public array $rateLimits,
        public ?bool $twoFactorRequired = null,
    ) {}
}
