<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RoleProfile;
use App\Support\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const ROLES = ['super-admin', 'admin', 'department-head', 'approver', 'submitter', 'viewer', 'auditor'];

    /** @var array<string, list<string>> */
    private const MATRIX = [
        'admin' => [
            'submissions.view.all', 'templates.manage', 'users.manage',
            'departments.manage', 'settings.manage', 'reports.view', 'webhooks.manage',
            'integrations.manage', 'submissions.fields.sensitive', 'audit.view',
        ],
        'department-head' => ['submissions.view.all', 'approvals.act', 'submissions.create', 'submissions.view.own'],
        'approver' => ['approvals.act'],
        'submitter' => ['submissions.create', 'submissions.view.own', 'submissions.withdraw'],
        'viewer' => ['submissions.view.own'],
        'auditor' => ['audit.view'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        collect(PermissionCatalogue::keys())
            ->merge(PermissionCatalogue::legacyKeys())
            ->merge(collect(self::MATRIX)->flatten())
            ->unique()
            ->each(fn (string $name) => Permission::findOrCreate($name));

        foreach (self::ROLES as $roleName) {
            $role = Role::findOrCreate($roleName);
            $permissions = self::MATRIX[$roleName] ?? [];
            if ($roleName === 'admin') {
                $permissions = array_values(array_unique([...$permissions, ...PermissionCatalogue::keys()]));
                $permissions = array_values(array_diff($permissions, ['roles.manage.custom']));
            }
            $role->syncPermissions($permissions);
        }

        foreach (Role::query()->orderBy('id')->get() as $role) {
            $builtIn = in_array($role->name, self::ROLES, true);
            RoleProfile::query()->firstOrCreate(
                ['role_id' => $role->id],
                [
                    'label' => Str::of($role->name)->replace('-', ' ')->title()->toString(),
                    'slug' => $this->uniqueSlug($role->name, $role->id),
                    'is_builtin' => $builtIn,
                    'is_protected' => $builtIn,
                    'is_archived' => false,
                ],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function uniqueSlug(string $name, int $roleId): string
    {
        $base = Str::slug($name) ?: 'role-'.$roleId;
        $slug = $base;
        $suffix = 2;
        while (RoleProfile::query()->where('slug', $slug)->where('role_id', '!=', $roleId)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
