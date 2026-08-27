<?php

namespace App\Services\Roles;

use App\Models\Role;
use App\Models\RoleChangeEvent;
use App\Models\RoleProfile;
use App\Models\User;
use App\Support\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

final class RolePermissionService
{
    /** @param list<string> $permissions */
    public function create(User $actor, string $label, ?string $description, array $permissions): Role
    {
        $this->assertManager($actor);
        $this->assertPermissionCeiling($actor, $permissions);

        return $this->persistNewRole($actor, $label, $description, $permissions, 'role_created');
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, mixed>|null  $before
     */
    private function persistNewRole(User $actor, string $label, ?string $description, array $permissions, string $action, ?array $before = null): Role
    {
        return DB::transaction(function () use ($actor, $label, $description, $permissions, $action, $before): Role {
            $slug = $this->uniqueSlug($label);
            $role = new Role;
            $role->name = $slug;
            $role->guard_name = 'web';
            $role->save();
            RoleProfile::query()->create([
                'role_id' => $role->id,
                'label' => $label,
                'slug' => $slug,
                'description' => $description,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $role->syncPermissions($permissions);
            $this->record($actor, $role, $action, $before, ['role_id' => $role->id, 'permissions' => $permissions]);

            return $role->load(['permissions']);
        });
    }

    /** @param list<string> $permissions */
    public function update(User $actor, Role $role, string $label, ?string $description, array $permissions): Role
    {
        $this->assertManager($actor);
        $this->assertEditable($role);
        $this->assertPermissionCeiling($actor, $permissions);

        return DB::transaction(function () use ($actor, $role, $label, $description, $permissions): Role {
            $locked = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $profile = RoleProfile::query()->where('role_id', $locked->id)->lockForUpdate()->firstOrFail();
            $before = ['role_id' => $locked->id, 'permissions' => $locked->permissions()->pluck('name')->sort()->values()->all()];
            $profile->update(['label' => $label, 'description' => $description, 'updated_by' => $actor->id]);
            $locked->syncPermissions($permissions);
            $this->record($actor, $locked, 'permissions_changed', $before, ['role_id' => $locked->id, 'permissions' => collect($permissions)->sort()->values()->all()]);

            return $locked->load(['permissions']);
        });
    }

    public function clone(User $actor, Role $source, string $label): Role
    {
        $this->assertManager($actor);
        if ($source->name === 'super-admin') {
            throw $this->validation('role', __('The internal System owner role cannot be cloned.'));
        }
        $permissions = [];
        foreach ($source->permissions()->pluck('name') as $name) {
            $permissions[] = (string) $name;
        }
        $this->assertPermissionCeiling($actor, $permissions);
        $description = RoleProfile::query()->where('role_id', $source->id)->value('description');

        return $this->persistNewRole($actor, $label, $description, $permissions, 'role_cloned', ['source_role_id' => $source->id]);
    }

    public function archive(User $actor, Role $role): void
    {
        $this->assertManager($actor);
        $this->assertEditable($role);
        DB::transaction(function () use ($actor, $role): void {
            $profile = RoleProfile::query()->where('role_id', $role->id)->lockForUpdate()->firstOrFail();
            $profile->update(['is_archived' => true, 'updated_by' => $actor->id]);
            $this->record($actor, $role, 'role_archived', ['is_archived' => false], ['is_archived' => true]);
        });
    }

    public function restore(User $actor, Role $role): void
    {
        $this->assertManager($actor);
        $profile = RoleProfile::query()->where('role_id', $role->id)->firstOrFail();
        if ($profile->is_protected || ! $profile->is_archived) {
            throw $this->validation('role', __('Only archived custom roles can be restored.'));
        }
        DB::transaction(function () use ($actor, $role): void {
            $profile = RoleProfile::query()->where('role_id', $role->id)->lockForUpdate()->firstOrFail();
            $profile->update(['is_archived' => false, 'updated_by' => $actor->id]);
            $this->record($actor, $role, 'role_restored', ['is_archived' => true], ['is_archived' => false]);
        });
    }

    /** @return list<string> */
    public function ceiling(User $actor): array
    {
        if ($actor->isSystemOwner()) {
            $keys = [];
            foreach (PermissionCatalogue::all() as $entry) {
                if ($entry['delegable'] === true || $entry['key'] === 'roles.manage.custom') {
                    $keys[] = (string) $entry['key'];
                }
            }

            return $keys;
        }

        $held = $actor->getAllPermissions()->pluck('name')->all();
        $keys = [];
        foreach (PermissionCatalogue::all() as $entry) {
            if ($entry['delegable'] !== true) {
                continue;
            }
            if (in_array($entry['key'], $held, true) || array_intersect($entry['legacy'], $held) !== []) {
                $keys[] = (string) $entry['key'];
            }
        }

        return $keys;
    }

    /** @param list<string> $permissions */
    public function assertPermissionCeiling(User $actor, array $permissions): void
    {
        $permissions = array_values(array_unique($permissions));
        if (array_diff($permissions, PermissionCatalogue::keys()) !== []) {
            throw $this->validation('permissions', __('One or more permissions are not available for custom roles.'));
        }
        if (array_diff($permissions, $this->ceiling($actor)) !== []) {
            throw $this->validation('permissions', __('A role cannot contain permissions above your permission ceiling.'));
        }
        foreach ($permissions as $permission) {
            $entry = PermissionCatalogue::find($permission);
            $dependencies = $entry['depends_on_any'] ?? [];
            if ($dependencies !== [] && array_intersect($dependencies, $permissions) === []) {
                throw $this->validation(
                    'permissions',
                    __('The :permission permission requires at least one related access permission.', ['permission' => $entry['label'] ?? $permission]),
                );
            }
            Permission::findOrCreate($permission);
        }
    }

    private function assertManager(User $actor): void
    {
        if (! $actor->isSystemOwner() && ! $actor->can('roles.manage.custom')) {
            throw $this->validation('role', __('You are not allowed to manage custom roles.'));
        }
    }

    private function assertEditable(Role $role): void
    {
        $profile = RoleProfile::query()->where('role_id', $role->id)->first();
        if ($role->name === 'super-admin' || $profile === null || $profile->is_protected || $profile->is_archived) {
            throw $this->validation('role', __('Protected, built-in, and archived roles cannot be edited.'));
        }
    }

    private function uniqueSlug(string $label): string
    {
        $base = Str::slug($label) ?: 'custom-role';
        $slug = $base;
        $suffix = 2;
        while (Role::query()->where('name', $slug)->exists() || RoleProfile::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(User $actor, Role $role, string $action, ?array $before, ?array $after): void
    {
        RoleChangeEvent::query()->create(['actor_id' => $actor->id, 'role_id' => $role->id, 'action' => $action, 'before' => $before, 'after' => $after]);
    }

    private function validation(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => $message]);
    }
}
