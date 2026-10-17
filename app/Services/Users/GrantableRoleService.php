<?php

namespace App\Services\Users;

use App\Enums\SystemRole;
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
            ->where('name', '!=', SystemRole::SuperAdmin->value)
            ->whereHas('profile', fn ($query) => $query->where('is_archived', false))
            ->with(['permissions', 'profile'])
            ->orderBy('name')
            ->get();

        $names = [];
        $ceiling = $this->permissions->ceiling($actor);
        foreach ($roles as $role) {
            if ($role->name === SystemRole::Admin->value) {
                if ($actor->can('system_owner')) {
                    $names[] = (string) $role->name;
                }

                continue;
            }
            if ($actor->can('system_owner')) {
                $names[] = (string) $role->name;

                continue;
            }
            if ($role->profile?->is_builtin) {
                if (! $actor->can('users.manage') && ! $actor->can('users.update')) {
                    continue;
                }
            } elseif (! $actor->can('roles.assign.custom')) {
                continue;
            }

            if (array_diff($role->permissions->pluck('name')->all(), $ceiling) === []) {
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
        if (! $actor->can('system_owner') && $target->hasRole(SystemRole::Admin->value)) {
            throw ValidationException::withMessages(['roles' => __('Only the System owner may change the administrator role.')]);
        }
    }
}
