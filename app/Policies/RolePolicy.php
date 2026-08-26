<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSystemOwner() || $user->can('roles.view') || $user->can('roles.manage.custom');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user) && ($role->name !== 'super-admin' || $user->isSystemOwner());
    }

    public function create(User $user): bool
    {
        return $user->isSystemOwner() || $user->can('roles.manage.custom');
    }

    public function update(User $user, Role $role): bool
    {
        return $this->create($user)
            && $role->name !== 'super-admin'
            && ! (bool) $role->profile?->is_protected
            && ! (bool) $role->profile?->is_archived;
    }

    public function clone(User $user, Role $role): bool
    {
        return $this->create($user) && $role->name !== 'super-admin';
    }

    public function archive(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    public function restore(User $user, Role $role): bool
    {
        return $this->create($user)
            && ! (bool) $role->profile?->is_protected
            && (bool) $role->profile?->is_archived;
    }
}
