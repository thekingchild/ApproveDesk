<?php

namespace App\Services\Roles;

use App\Models\Role;
use App\Models\RoleChangeEvent;
use App\Models\RoleProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

final class RoleAssignmentService
{
    public function __construct(private readonly RolePermissionService $permissions) {}

    /** @param list<string> $roles */
    public function sync(User $actor, User $target, array $roles): User
    {
        return DB::transaction(function () use ($actor, $target, $roles): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($locked->isSystemOwner()) {
                throw ValidationException::withMessages(['roles' => __('The System owner’s roles cannot be changed.')]);
            }

            $existing = array_values(DB::table(config('permission.table_names.model_has_roles'))
                ->where(config('permission.column_names.model_morph_key'), $locked->id)
                ->where('model_type', $locked->getMorphClass())
                ->orderBy('role_id')
                ->lockForUpdate()
                ->pluck('role_id')
                ->map(fn ($id): int => (int) $id)
                ->all());
            [$existingRoles, $resolved] = $this->resolveAndLock($existing, $roles);
            $ceiling = $this->permissions->ceiling($actor);

            if (! $actor->isSystemOwner()) {
                foreach ($existingRoles as $existingRole) {
                    if ($resolved->contains('id', $existingRole->id)) {
                        continue;
                    }

                    $profile = $existingRole->profile;
                    $cannotRemove = in_array($existingRole->name, ['admin', 'super-admin'], true)
                        || $profile === null
                        || $profile->is_archived
                        || ($profile->is_builtin && ! $actor->can('users.manage') && ! $actor->can('users.update'))
                        || (! $profile->is_builtin && ! $actor->can('roles.assign.custom'))
                        || array_diff($existingRole->permissions->pluck('name')->all(), $ceiling) !== [];

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

                $profile = $role->profile;
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

                    if (array_diff($role->permissions->pluck('name')->all(), $ceiling) !== []) {
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
     * @param  list<int>  $existingIds
     * @param  list<string>  $identifiers
     * @return array{Collection<int, Role>, Collection<int, Role>}
     */
    private function resolveAndLock(array $existingIds, array $identifiers): array
    {
        $requestedIds = Role::query()
            ->where(function ($query) use ($identifiers): void {
                $query->whereIn('name', $identifiers);
                $query->orWhereHas('profile', fn ($profile) => $profile->whereIn('slug', $identifiers));
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $roleIds = collect([...$existingIds, ...$requestedIds])->unique()->sort()->values();
        $roles = Role::query()->whereIn('id', $roleIds)->orderBy('id')->lockForUpdate()->get();
        $profiles = RoleProfile::query()->whereIn('role_id', $roleIds)->orderBy('id')->lockForUpdate()->get();

        $pivotTable = (string) config('permission.table_names.role_has_permissions');
        $permissionKey = (string) (config('permission.column_names.permission_pivot_key') ?: 'permission_id');
        $roleKey = (string) (config('permission.column_names.role_pivot_key') ?: 'role_id');
        $pivots = DB::table($pivotTable)
            ->whereIn($roleKey, $roleIds)
            ->orderBy($roleKey)
            ->orderBy($permissionKey)
            ->lockForUpdate()
            ->get([$roleKey, $permissionKey]);
        $permissionIds = $pivots->pluck($permissionKey)->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $permissions = Permission::query()->whereIn('id', $permissionIds)->orderBy('id')->lockForUpdate()->get();

        $profilesByRole = $profiles->keyBy('role_id');
        $permissionsById = $permissions->keyBy('id');
        $permissionIdsByRole = $pivots->groupBy($roleKey);
        foreach ($roles as $role) {
            $role->setRelation('profile', $profilesByRole->get($role->id));
            $role->setRelation('permissions', collect($permissionIdsByRole->get($role->id, []))
                ->map(fn (object $pivot) => $permissionsById->get($pivot->{$permissionKey}))
                ->filter()
                ->values());
        }

        $existingRoles = $roles->whereIn('id', $existingIds)->values();
        $requestedRoles = $roles->whereIn('id', $requestedIds)->values();
        if ($requestedRoles->count() !== count(array_unique($identifiers))) {
            throw ValidationException::withMessages(['roles' => __('One or more selected roles do not exist.')]);
        }

        return [$existingRoles, $requestedRoles];
    }
}
