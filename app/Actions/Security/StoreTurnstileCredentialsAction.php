<?php

namespace App\Actions\Security;

use App\DTOs\TurnstileSettingsData;
use App\Models\Setting;
use App\Services\Auth\Turnstile;

final class StoreTurnstileCredentialsAction
{
    public function execute(TurnstileSettingsData $data): void
    {
        Setting::put('turnstile.enabled', $data->enabled ? '1' : '0');
        Setting::put('turnstile.site_key', trim($data->siteKey));

        if ($data->clear) {
            Turnstile::clearSecret();

            return;
        }

        Turnstile::putSecret($data->secret);
    }
}
