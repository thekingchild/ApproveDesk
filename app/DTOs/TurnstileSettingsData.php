<?php

namespace App\DTOs;

/** Cloudflare Turnstile on Settings → Security & privacy (RV-ARC-13). */
final readonly class TurnstileSettingsData
{
    /**
     * @param  string|null  $secret  null or blank keeps the stored secret
     * @param  bool  $clear  removes the stored secret instead
     */
    public function __construct(
        public bool $enabled,
        public string $siteKey,
        public ?string $secret = null,
        public bool $clear = false,
    ) {}
}
