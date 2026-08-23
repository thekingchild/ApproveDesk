<?php

namespace App\Http\Responses;

use App\Support\RegistrationSettings;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Phase UO.2: after a successful password login, an unverified account in
 * OTP mode is bounced to the confirmation screen instead of the app.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();

        if ($user !== null
            && RegistrationSettings::requiresOtp()
            && $user->email_verified_at === null) {
            auth()->logout();
            $request->session()?->invalidate();
            $request->session()?->regenerateToken();

            session()->put('registration.confirm.email', $user->email);

            if ($request->wantsJson()) {
                return new JsonResponse(['two_factor' => false], 200);
            }

            return redirect()->route('registration.confirm.show');
        }

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false])
            : redirect()->intended(config('fortify.home'));
    }
}
