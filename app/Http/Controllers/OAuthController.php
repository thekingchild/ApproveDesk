<?php

namespace App\Http\Controllers;

use App\Actions\Auth\ResolveOAuthUserAction;
use App\Enums\OAuthProvider;
use App\Services\OAuthClient;
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

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function provider(string $provider): OAuthProvider
    {
        return OAuthProvider::tryFrom($provider) ?? abort(404);
    }
}
