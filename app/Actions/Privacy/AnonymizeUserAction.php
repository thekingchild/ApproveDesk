<?php

namespace App\Actions\Privacy;

use App\Enums\SystemRole;
use App\Models\Comment;
use App\Models\Delegation;
use App\Models\SubmissionWatcher;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Services\Security\DeviceSessionManager;
use App\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AnonymizeUserAction
{
    public function __construct(
        private readonly DeviceSessionManager $sessions,
        private readonly WorkflowEngine $engine,
    ) {}

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

        $photoPath = null;

        $anonymized = DB::transaction(function () use ($target, $actor, &$photoPath): User {
            /** @var User $target */
            $target = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            if ($target->hasRole(SystemRole::SuperAdmin->value)) {
                $otherActiveSuperAdmins = User::query()
                    ->whereKeyNot($target)
                    ->where('is_active', true)
                    ->role(SystemRole::SuperAdmin->value)
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

            // The stored photo is personal data too (RV-SEC-22); the file is
            // removed only once the transaction has committed.
            $photoPath = $target->profile_image_path;

            $target->forceFill([
                'name' => 'Former user',
                'email' => 'anonymized-'.$target->id.'@invalid.local',
                'phone' => null,
                'avatar' => null,
                'profile_image_path' => null,
                'profile_image_mime' => null,
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

            // Following a request is a live subscription, not history (RV-SEC-22).
            SubmissionWatcher::query()->where('user_id', $target->id)->delete();

            $this->endOpenDelegations($target, $actor);

            return $target->refresh();
        });

        if (is_string($photoPath) && $photoPath !== '') {
            Storage::disk('local')->delete($photoPath);
        }

        return $anonymized;
    }

    /**
     * Active and future delegations to or from the person end through the
     * engine so each one leaves an append-only DelegationRevoked record.
     * Delegations that already ended stay untouched as evidence (RV-SEC-22).
     */
    private function endOpenDelegations(User $target, User $actor): void
    {
        Delegation::query()
            ->where(fn ($query) => $query->where('delegator_id', $target->id)->orWhere('delegate_id', $target->id))
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->get()
            ->each(fn (Delegation $delegation) => $this->engine->revokeDelegation(
                $delegation,
                $actor,
                __('Delegation ended because a participant was anonymized.'),
            ));
    }

    /**
     * Anyone referenced by request history must be anonymized, never deleted:
     * deleting would fail on (or, before RV-ENG-06, silently rewrite) audit rows.
     */
    public static function hasPreservedAuditEvidence(User $user): bool
    {
        return $user->submissions()->exists()
            || WorkflowAction::query()
                ->where(fn ($query) => $query->where('actor_id', $user->id)
                    ->orWhere('delegated_to', $user->id)
                    ->orWhere('on_behalf_of_id', $user->id))
                ->exists()
            || Delegation::query()
                ->where(fn ($query) => $query->where('delegator_id', $user->id)->orWhere('delegate_id', $user->id))
                ->exists()
            || Comment::query()->where('author_id', $user->id)->exists();
    }
}
