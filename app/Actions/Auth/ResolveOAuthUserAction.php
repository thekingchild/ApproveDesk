<?php

namespace App\Actions\Auth;

use App\DTOs\OAuthUserData;
use App\Models\OAuthIdentity;
use App\Models\User;
use App\Services\Users\ActiveUserLimitService;
use App\Support\Branding;
use App\Support\RegistrationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResolveOAuthUserAction
{
    public function execute(OAuthUserData $data): User
    {
        return DB::transaction(function () use ($data): User {
            $identity = OAuthIdentity::query()
                ->with('user')
                ->where('provider', $data->provider)
                ->where('provider_user_id', $data->providerUserId)
                ->lockForUpdate()
                ->first();

            if ($identity !== null) {
                $identity->update(['email' => $data->email, 'avatar' => $data->avatar]);

                return $this->ensureActive($identity->user);
            }

            // An email the provider does not vouch for must never select or create an
            // account; only identities already linked (matched by provider id above) work.
            if (! $data->emailVerified) {
                throw ValidationException::withMessages([
                    'oauth' => __(':provider sign-in can only be used for accounts already connected to it. Sign in with your password, or ask the System owner to restrict :provider sign-in to your organisation\'s directory.', ['provider' => $data->provider->label()]),
                ]);
            }

            $user = User::query()->where('email', $data->email)->lockForUpdate()->first();

            if ($user === null) {
                if (! Branding::registrationOpen()) {
                    throw ValidationException::withMessages([
                        'oauth' => __('No ApproveDesk account exists for this email address and registration is disabled.'),
                    ]);
                }

                if (! RegistrationSettings::domainAllowed($data->email)) {
                    throw ValidationException::withMessages([
                        'oauth' => __('Please register with your organization email address.'),
                    ]);
                }

                $user = app(ActiveUserLimitService::class)->runActivation(1, function () use ($data): User {
                    $created = User::create([
                        'name' => $data->name,
                        'email' => $data->email,
                        'email_verified_at' => now(),
                        'password' => Str::password(48),
                        'avatar' => $data->avatar,
                        'is_active' => true,
                    ]);
                    $created->syncRoles(['submitter']);

                    return $created;
                });
            }

            $this->ensureActive($user);

            if (OAuthIdentity::query()->where('user_id', $user->id)->where('provider', $data->provider)->exists()) {
                throw ValidationException::withMessages([
                    'oauth' => __('This account is already linked to a different :provider identity.', ['provider' => $data->provider->label()]),
                ]);
            }

            OAuthIdentity::create([
                'user_id' => $user->id,
                'provider' => $data->provider,
                'provider_user_id' => $data->providerUserId,
                'email' => $data->email,
                'avatar' => $data->avatar,
            ]);

            return $user;
        });
    }

    private function ensureActive(User $user): User
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages(['oauth' => __('Your account has been deactivated.')]);
        }

        return $user;
    }
}
