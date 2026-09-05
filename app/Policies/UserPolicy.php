<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $target): bool
    {
        if (! $user->can('users.update')) {
            return false;
        }

        if ($target->isSystemOwner()) {
            return false;
        }

        return true;
    }

    public function assignRoles(User $actor, User $target): bool
    {
        return $actor->isSystemOwner() || ($actor->can('users.update') && ! $actor->is($target) && ! $target->isSystemOwner());
    }

    public function viewProfileImage(User $user, User $target): bool
    {
        return $user->is($target) || $user->can('users.view');
    }

    public function anonymize(User $user, User $target): bool
    {
        return $user->can('users.anonymize') && $this->update($user, $target) && ! $user->is($target);
    }

    public function activate(User $user, User $target): bool
    {
        return $user->can('users.activate') && $this->update($user, $target);
    }

    public function recover(User $user, User $target): bool
    {
        return $user->isSystemOwner()
            && ! $target->isSystemOwner()
            && ! $user->is($target);
    }

    public function updateActiveUserLimit(User $user): bool
    {
        return $user->isSystemOwner();
    }
}
