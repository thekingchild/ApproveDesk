<?php

namespace App\Actions\Privacy;

use App\Models\User;
use App\Models\WorkflowAction;
use App\Services\Security\DeviceSessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AnonymizeUserAction
{
    public function __construct(private readonly DeviceSessionManager $sessions) {}

    public function execute(User $target, User $actor): User
    {
        if ($target->isSystemOwner()) {
            throw ValidationException::withMessages([
                'user' => __('The System owner cannot be anonymized.'),
            ]);
        }

        if ($target->is($actor)) {
            throw ValidationException::withMessages([
                'user' => __('You cannot anonymize your own account.'),
            ]);
        }

        return DB::transaction(function () use ($target, $actor): User {
            /** @var User $target */
            $target = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            if ($target->hasRole('super-admin')) {
                $otherActiveSuperAdmins = User::query()
                    ->whereKeyNot($target)
                    ->where('is_active', true)
                    ->role('super-admin')
                    ->exists();

                if (! $otherActiveSuperAdmins) {
                    throw ValidationException::withMessages([
                        'user' => __('The final active super administrator cannot be anonymized.'),
                    ]);
                }
            }

            if ($target->isAnonymized()) {
                return $target;
            }

            $target->forceFill([
                'name' => 'Former user',
                'email' => 'anonymized-'.$target->id.'@invalid.local',
                'phone' => null,
                'avatar' => null,
                'teams_user_id' => null,
                'slack_user_id' => null,
                'telegram_user_id' => null,
                'password' => Str::password(32),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'is_active' => false,
            ])->save();

            if (method_exists($target, 'tokens')) {
                $target->tokens()->delete();
            }

            if (method_exists($target, 'passkeys')) {
                $target->passkeys()->delete();
            }

            $target->oauthIdentities()->delete();
            $target->pushSubscriptions()->delete();
            $this->sessions->revokeAll($target);

            unset($actor);

            return $target->refresh();
        });
    }

    public static function hasPreservedAuditEvidence(User $user): bool
    {
        return $user->submissions()->exists()
            || WorkflowAction::query()->where('actor_id', $user->id)->exists();
    }
}
