<?php

namespace App\Services;

use App\DTOs\OAuthUserData;
use App\Enums\OAuthProvider;
use App\Support\OAuthProviders;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OAuthClient
{
    public function redirect(OAuthProvider $provider, Request $request): RedirectResponse
    {
        abort_unless(OAuthProviders::enabled($provider), 404);

        $state = Str::random(64);
        $verifier = Str::random(96);
        $request->session()->put("oauth.{$provider->value}", [
            'state' => $state,
            'verifier' => $verifier,
            'created_at' => now()->timestamp,
        ]);

        return redirect()->away($this->authorizationEndpoint($provider).'?'.http_build_query([
            'client_id' => config("services.oauth.{$provider->value}.client_id"),
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $state,
            'code_challenge' => $this->codeChallenge($verifier),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], encoding_type: PHP_QUERY_RFC3986));
    }

    public function user(OAuthProvider $provider, Request $request): OAuthUserData
    {
        abort_unless(OAuthProviders::enabled($provider), 404);

        if ($request->filled('error')) {
            throw ValidationException::withMessages([
                'oauth' => __('The :provider sign-in request was cancelled or denied.', ['provider' => $provider->label()]),
            ]);
        }

        $attempt = $request->session()->pull("oauth.{$provider->value}");
        $state = $request->string('state')->toString();

        if (! is_array($attempt)
            || ! isset($attempt['state'], $attempt['verifier'], $attempt['created_at'])
            || ! is_string($attempt['state'])
            || ! is_string($attempt['verifier'])
            || ! is_int($attempt['created_at'])
            || now()->getTimestamp() - $attempt['created_at'] > 600
            || $state === ''
            || ! hash_equals($attempt['state'], $state)) {
            throw ValidationException::withMessages(['oauth' => __('The sign-in session is invalid or has expired. Please try again.')]);
        }

        $code = $request->string('code')->toString();

        if ($code === '') {
            throw ValidationException::withMessages(['oauth' => __('The identity provider did not return an authorization code.')]);
        }

        $token = Http::asForm()->acceptJson()->timeout(10)->post($this->tokenEndpoint($provider), [
            'client_id' => config("services.oauth.{$provider->value}.client_id"),
            'client_secret' => config("services.oauth.{$provider->value}.client_secret"),
            'redirect_uri' => $this->redirectUri($provider),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $attempt['verifier'],
        ])->throw()->json();

        $accessToken = is_array($token) ? ($token['access_token'] ?? null) : null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw ValidationException::withMessages(['oauth' => __('The identity provider did not return an access token.')]);
        }

        $profile = $this->http()->withToken($accessToken)->get($this->userInfoEndpoint($provider))->throw()->json();

        return $this->profileData($provider, is_array($profile) ? $profile : []);
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(10);
    }

    /** @param array<string, mixed> $profile */
    private function profileData(OAuthProvider $provider, array $profile): OAuthUserData
    {
        $id = $profile['sub'] ?? null;
        $email = $profile['email'] ?? $profile['preferred_username'] ?? null;
        $name = $profile['name'] ?? null;

        if (! is_string($id) || $id === ''
            || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($name) || trim($name) === '') {
            throw ValidationException::withMessages(['oauth' => __('The identity provider did not return a usable name and email address.')]);
        }

        if ($provider === OAuthProvider::Google && ($profile['email_verified'] ?? false) !== true) {
            throw ValidationException::withMessages(['oauth' => __('Google did not confirm that this email address is verified.')]);
        }

        return new OAuthUserData(
            provider: $provider,
            providerUserId: $id,
            name: trim($name),
            email: Str::lower($email),
            avatar: is_string($profile['picture'] ?? null) ? $profile['picture'] : null,
        );
    }

    private function authorizationEndpoint(OAuthProvider $provider): string
    {
        return match ($provider) {
            OAuthProvider::Google => 'https://accounts.google.com/o/oauth2/v2/auth',
            OAuthProvider::Microsoft => 'https://login.microsoftonline.com/'.$this->microsoftTenant().'/oauth2/v2.0/authorize',
        };
    }

    private function tokenEndpoint(OAuthProvider $provider): string
    {
        return match ($provider) {
            OAuthProvider::Google => 'https://oauth2.googleapis.com/token',
            OAuthProvider::Microsoft => 'https://login.microsoftonline.com/'.$this->microsoftTenant().'/oauth2/v2.0/token',
        };
    }

    private function userInfoEndpoint(OAuthProvider $provider): string
    {
        return match ($provider) {
            OAuthProvider::Google => 'https://openidconnect.googleapis.com/v1/userinfo',
            OAuthProvider::Microsoft => 'https://graph.microsoft.com/oidc/userinfo',
        };
    }

    private function redirectUri(OAuthProvider $provider): string
    {
        return (string) (config("services.oauth.{$provider->value}.redirect")
            ?: route('oauth.callback', $provider->value));
    }

    private function microsoftTenant(): string
    {
        $tenant = (string) config('services.oauth.microsoft.tenant', 'common');

        return preg_match('/^[a-zA-Z0-9.-]+$/', $tenant) === 1 ? $tenant : 'common';
    }

    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
