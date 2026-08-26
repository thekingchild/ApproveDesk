<?php

namespace App\Services\Users;

use App\Models\Role;
use App\Models\User;
use App\Services\Roles\RolePermissionService;
use Illuminate\Validation\ValidationException;

final class GrantableRoleService
{
    public function __construct(private readonly RolePermissionService $permissions) {}

    /** @return list<string> */
    public function for(User $actor): array
    {
        $roles = Role::query()
            ->where('name', '!=', 'super-admin')
            ->whereHas('profile', fn ($query) => $query->where('is_archived', false))
            ->with(['permissions', 'profile'])
            ->orderBy('name')
            ->get();

        $names = [];
        foreach ($roles as $role) {
            if ($role->name === 'admin') {
                if ($actor->isSystemOwner()) {
                    $names[] = (string) $role->name;
                }

                continue;
            }
            if ($actor->isSystemOwner()) {
                $names[] = (string) $role->name;

                continue;
            }
            if ($role->profile?->is_builtin) {
                if ($actor->can('users.manage') || $actor->can('users.update')) {
                    $names[] = (string) $role->name;
                }

                continue;
            }
            if (! $actor->can('roles.assign.custom')) {
                continue;
            }

            if (array_diff($role->permissions->pluck('name')->all(), $this->permissions->ceiling($actor)) === []) {
                $names[] = (string) $role->name;
            }
        }

        return $names;
    }

    /** @param list<string> $roles */
    public function assertCanAssign(User $actor, array $roles): void
    {
        if (array_diff($roles, $this->for($actor)) !== []) {
            throw ValidationException::withMessages(['roles' => __('You cannot grant one or more of the selected roles.')]);
        }
    }

    /** @param list<string> $roles */
    public function assertCanSync(User $actor, User $target, array $roles): void
    {
        $this->assertCanAssign($actor, $roles);
        if (! $actor->isSystemOwner() && $target->hasRole('admin')) {
            throw ValidationException::withMessages(['roles' => __('Only the System owner may change the administrator role.')]);
        }
    }
}
