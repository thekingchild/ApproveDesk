<?php

namespace App\Actions\Users;

use App\Models\AccountRecoveryEvent;
use App\Models\User;
use App\Notifications\AccountRecoveryInitiatedNotification;
use App\Services\Security\DeviceSessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ResetUserSecondFactorAction
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

        DB::transaction(function () use ($target, $actor): void {
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            if (method_exists($locked, 'passkeys')) {
                $locked->passkeys()->delete();
            }

            if (method_exists($locked, 'tokens')) {
                $locked->tokens()->delete();
            }

            $this->sessions->revokeAll($locked);

            AccountRecoveryEvent::query()->create([
                'actor_id' => $actor->id,
                'target_id' => $locked->id,
                'action' => AccountRecoveryEvent::ACTION_SECOND_FACTOR_RESET,
                'metadata' => ['result' => 'cleared'],
            ]);
        });

        $target->refresh()->notify(new AccountRecoveryInitiatedNotification('second_factor'));
    }
}
