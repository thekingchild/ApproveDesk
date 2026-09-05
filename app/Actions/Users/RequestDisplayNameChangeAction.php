<?php

namespace App\Actions\Users;

use App\Models\DisplayNameChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RequestDisplayNameChangeAction
{
    public function execute(User $user, string $name): DisplayNameChange
    {
        $name = Str::squish(strip_tags($name));
        if (Str::length($name) < 2 || Str::length($name) > 255) {
            throw ValidationException::withMessages(['name' => __('The name must be between 2 and 255 characters.')]);
        }
        if (Str::lower($name) === Str::lower(Str::squish($user->name))) {
            throw ValidationException::withMessages(['name' => __('Enter a different name.')]);
        }

        return DB::transaction(function () use ($user, $name): DisplayNameChange {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $pending = DisplayNameChange::query()->where('user_id', $user->id)->where('status', 'pending')->lockForUpdate()->first();
            $values = ['pending_user_id' => $user->id, 'proposed_name' => $name, 'normalized_name' => Str::lower($name), 'status' => 'pending', 'decided_by' => null, 'decided_at' => null, 'reason' => null];
            if ($pending) {
                $pending->update($values);
                $change = $pending;
            } else {
                $change = DisplayNameChange::query()->create(['user_id' => $user->id, ...$values]);
            }
            activity()->performedOn($change)->causedBy($user)->event('display-name-requested')->log('Display name change requested');

            return $change;
        });
    }

    public function cancel(User $user, DisplayNameChange $change): void
    {
        DB::transaction(function () use ($user, $change): void {
            $locked = DisplayNameChange::query()->lockForUpdate()->findOrFail($change->id);
            abort_unless($locked->user_id === $user->id && $locked->status === 'pending', 403);
            $locked->update(['status' => 'cancelled', 'pending_user_id' => null, 'decided_at' => now()]);
            activity()->performedOn($locked)->causedBy($user)->event('display-name-cancelled')->log('Display name change cancelled');
        });
    }
}
