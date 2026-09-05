<?php

namespace App\Support;

use App\Enums\OAuthProvider;

final class OAuthProviders
{
    public static function enabled(OAuthProvider $provider): bool
    {
        return SystemSettings::oauthEnabled($provider->value);
    }

    /** @return array<int, OAuthProvider> */
    public static function enabledProviders(): array
    {
        return array_values(array_filter(OAuthProvider::cases(), self::enabled(...)));
    }
}
