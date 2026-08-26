<?php

namespace App\Services\Roles;

use App\Models\Role;
use App\Models\RoleChangeEvent;
use App\Models\RoleProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RoleAssignmentService
{
    public function __construct(private readonly RolePermissionService $permissions) {}

    /** @param list<string> $roles */
    public function sync(User $actor, User $target, array $roles): User
    {
        if ($target->isSystemOwner()) {
            throw ValidationException::withMessages(['roles' => __('The System owner’s roles cannot be changed.')]);
        }
        $resolved = $this->resolve($roles);
        if ($resolved->isEmpty()) {
            throw ValidationException::withMessages(['roles' => __('Assign at least one role.')]);
        }
        $existing = $target->roles()->pluck('roles.id')->all();
        foreach ($resolved as $role) {
            if ($role->name === 'super-admin' || ($role->name === 'admin' && ! $actor->isSystemOwner())) {
                throw ValidationException::withMessages(['roles' => __('Only the System owner may assign Administrator authority.')]);
            }
            $profile = RoleProfile::query()->where('role_id', $role->id)->first();
            if ($profile === null) {
                throw ValidationException::withMessages(['roles' => __('That role has no managed profile.')]);
            }
            if ($profile->is_builtin && ! $actor->isSystemOwner() && $role->name !== 'admin'
                && ! $actor->can('users.manage') && ! $actor->can('users.update')) {
                throw ValidationException::withMessages(['roles' => __('You cannot grant one or more of the selected roles.')]);
            }
            if ($profile->is_archived && ! in_array($role->id, $existing, true)) {
                throw ValidationException::withMessages(['roles' => __('Archived roles cannot be newly assigned.')]);
            }
            if (! $profile->is_builtin && ! $actor->isSystemOwner()) {
                if (! $actor->can('roles.assign.custom')) {
                    throw ValidationException::withMessages(['roles' => __('You are not allowed to assign custom roles.')]);
                }
                $this->permissions->assertPermissionCeiling($actor, $role->permissions()->pluck('name')->all());
            }
        }

        return DB::transaction(function () use ($actor, $target, $resolved, $existing): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $new = $resolved->pluck('id')->all();
            $locked->syncRoles($resolved);
            foreach (array_diff($new, $existing) as $roleId) {
                RoleChangeEvent::query()->create(['actor_id' => $actor->id, 'role_id' => $roleId, 'target_user_id' => $locked->id, 'action' => 'role_assigned', 'after' => ['role_id' => $roleId, 'user_id' => $locked->id]]);
            }
            foreach (array_diff($existing, $new) as $roleId) {
                RoleChangeEvent::query()->create(['actor_id' => $actor->id, 'role_id' => $roleId, 'target_user_id' => $locked->id, 'action' => 'role_removed', 'before' => ['role_id' => $roleId, 'user_id' => $locked->id]]);
            }

            return $locked->load('roles');
        });
    }

    /** @param list<string> $identifiers @return \Illuminate\Support\Collection<int, Role> */
    private function resolve(array $identifiers): Collection
    {
        $profiles = RoleProfile::query()->whereIn('slug', $identifiers)->pluck('role_id');
        $roles = Role::query()
            ->where(function ($query) use ($identifiers, $profiles): void {
                $query->whereIn('name', $identifiers);
                if ($profiles->isNotEmpty()) {
                    $query->orWhereIn('id', $profiles);
                }
            })
            ->get();
        if ($roles->count() !== count(array_unique($identifiers))) {
            throw ValidationException::withMessages(['roles' => __('One or more selected roles do not exist.')]);
        }

        return $roles;
    }
}
