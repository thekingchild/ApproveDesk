<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

final class SecondFactorAssurance
{
    public const SESSION_KEY = 'auth.second_factor_assurance';

    private const METHODS = ['totp', 'recovery_code', 'passkey', 'enrollment'];

    public function mark(Request $request, User $user, string $method): void
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException("Unsupported second-factor method [{$method}].");
        }

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, [
            'user_id' => (string) $user->getAuthIdentifier(),
            'verified_at' => now()->timestamp,
            'method' => $method,
        ]);
    }

    public function clear(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    public function satisfied(Request $request, User $user): bool
    {
        if ($request->hasSession() && $this->markerSatisfies($request->session()->get(self::SESSION_KEY), $user)) {
            return true;
        }

        try {
            return $this->markerSatisfies(session()->get(self::SESSION_KEY), $user);
        } catch (\Throwable) {
            return false;
        }
    }

    private function markerSatisfies(mixed $marker, User $user): bool
    {
        if (! is_array($marker)
            || array_keys($marker) !== ['user_id', 'verified_at', 'method']
            || ! is_string($marker['user_id'])
            || ! is_int($marker['verified_at'])
            || ! is_string($marker['method'])
            || ! in_array($marker['method'], self::METHODS, true)) {
            return false;
        }

        return $marker['user_id'] === (string) $user->getAuthIdentifier();
    }
}
