<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $target): bool
    {
        if (! $user->can('users.manage')) {
            return false;
        }

        return ! $target->hasRole('super-admin') || $user->hasRole('super-admin');
    }

    public function anonymize(User $user, User $target): bool
    {
        return $this->update($user, $target) && ! $user->is($target);
    }
}
