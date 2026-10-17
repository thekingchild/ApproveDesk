<?php

namespace App\Actions\Users;

use App\Enums\DisplayNameChangeStatus;
use App\Models\DisplayNameChange;
use App\Models\User;
use App\Notifications\DisplayNameChangeDecidedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DecideDisplayNameChangeAction
{
    public function approve(User $actor, DisplayNameChange $change): void
    {
        $this->decide($actor, $change, DisplayNameChangeStatus::Approved, null);
    }

    public function reject(User $actor, DisplayNameChange $change, ?string $reason = null): void
    {
        $this->decide($actor, $change, DisplayNameChangeStatus::Rejected, $reason);
    }

    private function decide(User $actor, DisplayNameChange $change, DisplayNameChangeStatus $status, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $change, $status, $reason): void {
            $locked = DisplayNameChange::query()->with('user')->lockForUpdate()->findOrFail($change->id);
            if (! $actor->can('update', $locked->user)) {
                throw new AuthorizationException;
            }
            if ($actor->is($locked->user)) {
                throw ValidationException::withMessages(['decision' => __('You cannot decide your own name change.')]);
            }
            if ($locked->status !== DisplayNameChangeStatus::Pending) {
                throw ValidationException::withMessages(['decision' => __('This request has already been decided.')]);
            }
            if ($status === DisplayNameChangeStatus::Approved) {
                $locked->user->update(['name' => $locked->proposed_name]);
            }
            $locked->update(['status' => $status, 'pending_user_id' => null, 'decided_by' => $actor->id, 'decided_at' => now(), 'reason' => $reason]);
            activity()->performedOn($locked)->causedBy($actor)->event('display-name-'.$status->value)->log('Display name change '.$status->value);
            DB::afterCommit(fn () => $locked->user->notify(new DisplayNameChangeDecidedNotification($status->value, $locked->proposed_name, $reason)));
        });
    }
}
