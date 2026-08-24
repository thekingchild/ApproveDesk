<?php

namespace App\Actions\Security;

use App\Support\Turnstile;

final class StoreTurnstileCredentialsAction
{
    /**
     * @param  array{secret?: string|null, clear?: bool}  $input
     */
    public function execute(array $input): void
    {
        if (! empty($input['clear'])) {
            Turnstile::clearSecret();

            return;
        }

        Turnstile::putSecret($input['secret'] ?? null);
    }
}
