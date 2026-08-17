<?php

namespace App\DTOs;

use App\Enums\OAuthProvider;

final readonly class OAuthUserData
{
    public function __construct(
        public OAuthProvider $provider,
        public string $providerUserId,
        public string $name,
        public string $email,
        public ?string $avatar,
    ) {}
}
