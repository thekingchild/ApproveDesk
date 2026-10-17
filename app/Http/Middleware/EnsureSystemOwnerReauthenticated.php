<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\SecondFactorAssurance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureSystemOwnerReauthenticated
{
    public function __construct(private SecondFactorAssurance $assurance) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSystemOwner()) {
            abort(403);
        }

        if (! $user->hasSatisfiedSecondFactor() || ! $this->assurance->satisfied($request, $user)) {
            abort(403, __('Confirm an authenticator or passkey in this session before recovering another account.'));
        }

        return $next($request);
    }
}
