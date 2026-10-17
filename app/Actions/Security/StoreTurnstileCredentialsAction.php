<?php

namespace App\Actions\Security;

use App\Models\Setting;
use App\Services\Auth\Turnstile;

final class StoreTurnstileCredentialsAction
{
    /**
     * @param  array{enabled?: bool, site_key?: string|null, secret?: string|null, clear?: bool}  $input
     */
    public function execute(array $input): void
    {
        if (array_key_exists('enabled', $input)) {
            Setting::put('turnstile.enabled', ! empty($input['enabled']) ? '1' : '0');
        }

        if (array_key_exists('site_key', $input)) {
            Setting::put('turnstile.site_key', trim((string) $input['site_key']));
        }

        if (! empty($input['clear'])) {
            Turnstile::clearSecret();

            return;
        }

        Turnstile::putSecret($input['secret'] ?? null);
    }
}
