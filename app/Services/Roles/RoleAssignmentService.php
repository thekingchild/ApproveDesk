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
        return DB::transaction(function () use ($actor, $target, $roles): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $resolved = $this->resolve($roles);
            if ($locked->isSystemOwner()) {
                throw ValidationException::withMessages(['roles' => __('The System owner’s roles cannot be changed.')]);
            }

            $existing = $locked->roles()->lockForUpdate()->pluck('roles.id')->map(fn ($id): int => (int) $id)->all();
            $existingRoles = Role::query()->whereIn('id', $existing)->orderBy('id')->lockForUpdate()->get();
            $ceiling = $this->permissions->ceiling($actor);

            if (! $actor->isSystemOwner()) {
                foreach ($existingRoles as $existingRole) {
                    if ($resolved->contains('id', $existingRole->id)) {
                        continue;
                    }

                    $profile = RoleProfile::query()->where('role_id', $existingRole->id)->lockForUpdate()->first();
                    $cannotRemove = in_array($existingRole->name, ['admin', 'super-admin'], true)
                        || $profile === null
                        || $profile->is_archived
                        || ($profile->is_builtin && ! $actor->can('users.manage') && ! $actor->can('users.update'))
                        || (! $profile->is_builtin && ! $actor->can('roles.assign.custom'))
                        || array_diff($existingRole->permissions()->pluck('name')->all(), $ceiling) !== [];

                    if ($cannotRemove) {
                        $resolved->push($existingRole);
                    }
                }
            }

            $resolved = $resolved->unique('id')->values();
            $new = $resolved->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
            sort($existing);
            sort($new);

            if (! $actor->isSystemOwner() && $actor->is($locked) && $new !== $existing) {
                throw ValidationException::withMessages(['roles' => __('You cannot change your own roles.')]);
            }
            if ($resolved->isEmpty()) {
                throw ValidationException::withMessages(['roles' => __('Assign at least one role.')]);
            }

            foreach ($resolved as $role) {
                if ($role->name === 'super-admin') {
                    throw ValidationException::withMessages(['roles' => __('The internal System owner role cannot be assigned.')]);
                }
                if ($role->name === 'admin') {
                    if (! $actor->isSystemOwner() && ! in_array($role->id, $existing, true)) {
                        throw ValidationException::withMessages(['roles' => __('Only the System owner may assign Administrator authority.')]);
                    }

                    continue;
                }
                if (in_array($role->id, $existing, true)) {
                    continue;
                }

                $profile = RoleProfile::query()->where('role_id', $role->id)->first();
                if ($profile === null) {
                    throw ValidationException::withMessages(['roles' => __('That role has no managed profile.')]);
                }
                if ($profile->is_archived && ! in_array($role->id, $existing, true)) {
                    throw ValidationException::withMessages(['roles' => __('Archived roles cannot be newly assigned.')]);
                }
                if (! $actor->isSystemOwner()) {
                    if ($profile->is_builtin) {
                        if (! $actor->can('users.manage') && ! $actor->can('users.update')) {
                            throw ValidationException::withMessages(['roles' => __('You cannot grant one or more of the selected roles.')]);
                        }
                    } elseif (! $actor->can('roles.assign.custom')) {
                        throw ValidationException::withMessages(['roles' => __('You are not allowed to assign custom roles.')]);
                    }

                    if (array_diff($role->permissions()->pluck('name')->all(), $ceiling) !== []) {
                        throw ValidationException::withMessages(['roles' => __('A role cannot contain permissions above your permission ceiling.')]);
                    }
                }
            }

            $existingAdmin = Role::query()->where('name', 'admin')->whereIn('id', $existing)->exists();
            $newAdmin = $resolved->contains(fn (Role $role): bool => $role->name === 'admin');
            if (! $actor->isSystemOwner() && $existingAdmin !== $newAdmin) {
                throw ValidationException::withMessages(['roles' => __('Only the System owner may change the administrator role.')]);
            }

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

    /**
     * @param  list<string>  $identifiers
     * @return Collection<int, Role>
     */
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
