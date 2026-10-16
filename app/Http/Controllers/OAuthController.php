<?php

namespace App\Http\Controllers;

use App\Actions\Auth\ResolveOAuthUserAction;
use App\Enums\OAuthProvider;
use App\Services\OAuthClient;
use App\Support\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class OAuthController extends Controller
{
    public function redirect(string $provider, Request $request, OAuthClient $client): RedirectResponse
    {
        return $client->redirect($this->provider($provider), $request);
    }

    public function callback(
        string $provider,
        Request $request,
        OAuthClient $client,
        ResolveOAuthUserAction $resolveUser,
    ): RedirectResponse {
        try {
            $user = $resolveUser->execute($client->user($this->provider($provider), $request));
        } catch (ValidationException $exception) {
            return redirect()->route('login')->withErrors($exception->errors());
        } catch (Throwable $exception) {
            if (app()->runningUnitTests()) {
                throw $exception;
            }

            report($exception);

            return redirect()->route('login')->withErrors([
                'oauth' => __('The external sign-in could not be completed. Please try again.'),
            ]);
        }

        $remember = ! SystemSettings::expireOnClose();

        // Social sign-in is a first factor only: accounts with an authenticator go
        // through the same challenge as a password sign-in (RV-SEC-20).
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => $remember]);

            return redirect()->route('two-factor.login');
        }

        if ($user->passkeys()->exists()) {
            return redirect()->route('login')->withErrors([
                'oauth' => __('This account uses a passkey. Sign in with your passkey instead.'),
            ]);
        }

        Auth::login($user, remember: $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function provider(string $provider): OAuthProvider
    {
        return OAuthProvider::tryFrom($provider) ?? abort(404);
    }
}
