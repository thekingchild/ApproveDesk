<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\Auth\RegistrationOtpService;
use App\Support\RegistrationSettings;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * After self-registration: OTP mode sends the user to the confirmation
 * screen without a session; immediate mode keeps Fortify's default home redirect.
 */
class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        if (RegistrationSettings::requiresOtp()) {
            // Registered event already fired; do NOT log the user in until
            // the email OTP is confirmed.
            auth()->logout();
            $request->session()?->invalidate();
            $request->session()?->regenerateToken();

            // Send the confirmation code now that the account exists.
            $user = User::query()->where('email', (string) $request->input('email'))->first();

            if ($user !== null && $user->email_verified_at === null) {
                try {
                    app(RegistrationOtpService::class)->issue($user);
                } catch (\RuntimeException) {
                    // Throttled or mail hiccup: the confirmation screen offers resend.
                }
            }

            session()->put('registration.confirm.email', (string) $request->input('email'));

            if ($request->expectsJson()) {
                return new JsonResponse(['two_factor' => false], 201);
            }

            return redirect()->route('registration.confirm.show');
        }

        return $request->wantsJson()
            ? new JsonResponse([], 201)
            : redirect()->intended(config('fortify.home'));
    }
}
