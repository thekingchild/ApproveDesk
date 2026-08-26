<?php

namespace App\Actions\Users;

use App\Models\AccountRecoveryEvent;
use App\Models\User;
use App\Notifications\AccountRecoveryInitiatedNotification;
use App\Services\Security\DeviceSessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetUserPasswordAction
{
    public function __construct(private readonly DeviceSessionManager $sessions) {}

    public function execute(User $target, User $actor): void
    {
        Gate::forUser($actor)->authorize('recover', $target);

        if ($target->isSystemOwner() || $target->is($actor)) {
            throw ValidationException::withMessages([
                'user' => __('This account cannot be recovered here.'),
            ]);
        }

        $status = Password::sendResetLink(['email' => $target->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'user' => __('The password reset email could not be sent. The current password was left unchanged.'),
            ]);
        }

        DB::transaction(function () use ($target, $actor): void {
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'password' => Str::password(32),
                'remember_token' => null,
            ])->save();

            $locked->tokens()->delete();

            $this->sessions->revokeAll($locked);

            AccountRecoveryEvent::query()->create([
                'actor_id' => $actor->id,
                'target_id' => $locked->id,
                'action' => AccountRecoveryEvent::ACTION_PASSWORD_RESET,
                'metadata' => ['result' => 'sent'],
            ]);
        });

        $target->refresh()->notify(new AccountRecoveryInitiatedNotification('password'));
    }
}
