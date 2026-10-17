<?php

namespace App\Actions\Security;

use App\Services\Auth\Turnstile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class VerifyTurnstileAction
{
    public function execute(?Request $request = null): void
    {
        $request ??= request();

        if ($request->attributes->get('turnstile.verified') === true) {
            return;
        }

        if (! Turnstile::enabled()) {
            return;
        }

        $token = (string) $request->input(Turnstile::TOKEN_FIELD);
        if ($token === '' || ! Turnstile::verify($token, $request->ip())) {
            throw ValidationException::withMessages([
                'email' => __('Please try again.'),
            ]);
        }

        $request->attributes->set('turnstile.verified', true);
    }
}
