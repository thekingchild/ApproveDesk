<?php

namespace App\Http\Middleware;

use App\Support\SecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSecondFactorWhenRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! SecuritySettings::twoFactorRequired() || $user->hasSatisfiedSecondFactor()) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->header('X-Livewire') !== null) {
            abort(403, __('A second factor is required before you can use ApproveDesk.'));
        }

        return redirect()
            ->route('security.edit')
            ->with('second_factor_required', true);
    }

    private function isExempt(Request $request): bool
    {
        return $request->routeIs(
            'logout',
            'password.confirm',
            'password.confirm.store',
            'security.edit',
            'two-factor.*',
            'passkey.*',
            'help',
            'help.show',
            'branding.assets',
            'well-known.passkeys',
        )
            || $request->is('logout')
            || $request->is('user/confirm-password')
            || $request->is('user/two-factor-authentication')
            || $request->is('user/confirmed-two-factor-authentication')
            || $request->is('user/two-factor-*')
            || $request->is('user/passkeys')
            || $request->is('user/passkeys/*')
            || $request->is('two-factor-challenge')
            || $request->is('passkeys/*')
            || $request->is('settings/security')
            || $request->is('settings/security/*')
            || $request->is('livewire/*')
            || $request->is('up')
            || $request->is('branding/assets/*')
            || $request->is('help')
            || $request->is('help/*');
    }
}
