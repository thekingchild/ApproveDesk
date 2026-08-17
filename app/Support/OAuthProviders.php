<?php

namespace App\Support;

use App\Enums\OAuthProvider;

final class OAuthProviders
{
    public static function enabled(OAuthProvider $provider): bool
    {
        return filled(config("services.oauth.{$provider->value}.client_id"))
            && filled(config("services.oauth.{$provider->value}.client_secret"));
    }

    /** @return array<int, OAuthProvider> */
    public static function enabledProviders(): array
    {
        return array_values(array_filter(OAuthProvider::cases(), self::enabled(...)));
    }
}
