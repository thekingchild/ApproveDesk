<?php

namespace App\Http\Middleware;

use App\Services\Auth\RegistrationOtpService;
use App\Support\RegistrationSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase UO.2: in OTP mode, an unverified self-registered account cannot hold
 * a session. Login attempts bounce to the confirmation screen with resend.
 */
class RedirectUnconfirmedRegistration
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user !== null
            && RegistrationSettings::requiresOtp()
            && $user->email_verified_at === null
            && app(RegistrationOtpService::class)->hasPending($user->email)
            && ! $request->is('registration/confirm*')
            && ! $request->is('logout')
            && ! $request->is('email/verify*')
            && ! $request->is('email/verification-notification')
        ) {
            Auth::logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $request->session()->put('registration.confirm.email', $user->email);
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => __('Confirm your email address before continuing.')], 403);
            }

            return redirect()
                ->route('registration.confirm.show')
                ->withErrors(['email' => __('Confirm your email address before signing in.')]);
        }

        return $next($request);
    }
}
