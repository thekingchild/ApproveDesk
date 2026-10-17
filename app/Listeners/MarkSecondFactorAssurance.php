<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\SecondFactorAssurance;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

final class MarkSecondFactorAssurance
{
    public function __construct(
        private readonly Request $request,
        private readonly SecondFactorAssurance $assurance,
    ) {}

    public function handle(
        ValidTwoFactorAuthenticationCodeProvided|TwoFactorAuthenticationConfirmed|PasskeyVerified|PasskeyRegistered $event,
    ): void {
        if (! $this->request->hasSession() || ! $event->user instanceof User) {
            return;
        }

        $authenticatedUser = $this->request->user();

        if (($event instanceof TwoFactorAuthenticationConfirmed || $event instanceof PasskeyRegistered)
            && (! $authenticatedUser instanceof User || ! $authenticatedUser->is($event->user))) {
            return;
        }

        if ($authenticatedUser !== null && ! $authenticatedUser->is($event->user)) {
            return;
        }

        $method = match (true) {
            $event instanceof PasskeyVerified => 'passkey',
            $event instanceof TwoFactorAuthenticationConfirmed,
            $event instanceof PasskeyRegistered => 'enrollment',
            $this->request->filled('recovery_code') => 'recovery_code',
            default => 'totp',
        };
        $this->assurance->mark($this->request, $event->user, $method);
    }
}
