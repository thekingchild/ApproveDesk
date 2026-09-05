<?php

namespace App\Enums;

enum OAuthProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
    case GitHub = 'github';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Microsoft => 'Microsoft',
            self::GitHub => 'GitHub',
        };
    }
}
