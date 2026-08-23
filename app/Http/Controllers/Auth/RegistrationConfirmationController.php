<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\RegistrationConfirmRequest;
use App\Models\User;
use App\Services\Auth\RegistrationOtpService;
use App\Support\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Email OTP confirmation for self-registered accounts (Phase UO.2).
 * The user is not authenticated during this flow.
 */
class RegistrationConfirmationController
{
    public function __construct(private readonly RegistrationOtpService $otp) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('register');
        }

        return view('pages::auth.confirm-registration', [
            'email' => $user->email,
            'throttledUntil' => $this->otp->resendThrottledUntil($user->email),
        ]);
    }

    public function verify(RegistrationConfirmRequest $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('register');
        }

        [$ok, $error] = $this->otp->verify($user, (string) $request->input('code'));

        if (! $ok) {
            return back()->withErrors(['code' => $error])->withInput();
        }

        $request->session()->forget('registration.confirm.email');

        return redirect()->route('login')->with('status', __('Your email is confirmed. You can sign in now.'));
    }

    /** Resend is throttled inside the OTP service; failures surface inline. */
    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('register');
        }

        try {
            $this->otp->issue($user);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        return back()->with('status', __('A new confirmation code was sent.'));
    }

    /** The pending (unverified) account remembered in the session. */
    private function pendingUser(Request $request): ?User
    {
        $email = (string) $request->session()->get('registration.confirm.email');

        if ($email === '' || ! RegistrationSettings::requiresOtp()) {
            return null;
        }

        $user = User::query()->where('email', $email)->first();

        // Already confirmed or deactivated: nothing to confirm here.
        if ($user === null || $user->email_verified_at !== null || ! $user->is_active) {
            return null;
        }

        return $user;
    }
}
