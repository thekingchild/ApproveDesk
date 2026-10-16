<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Roles\RolePermissionService;

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

    /**
     * Identity-level changes (email, deactivation, anonymization) to peer or
     * higher administrators, or to anyone holding permissions above the actor's
     * ceiling, belong to the System owner alone. Otherwise an administrator could
     * point a stronger account's email at their own mailbox and reset its
     * password (RV-SEC-04).
     */
    public function manageIdentity(User $user, User $target): bool
    {
        if (! $this->update($user, $target)) {
            return false;
        }

        return $user->isSystemOwner()
            || (! $target->hasAnyRole(['admin', 'super-admin'])
                && ! app(RolePermissionService::class)->exceedsCeiling($user, $target));
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
        return $user->can('users.anonymize') && $this->manageIdentity($user, $target) && ! $user->is($target);
    }

    public function activate(User $user, User $target): bool
    {
        return $user->can('users.activate') && $this->manageIdentity($user, $target);
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
