<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const ROLES = ['super-admin', 'admin', 'department-head', 'approver', 'submitter', 'viewer'];

    /**
     * Permission matrix — role => permissions granted. The super-admin role
     * is deliberately absent: Gate::before grants it everything implicitly.
     */
    private const MATRIX = [
        'admin' => [
            'submissions.view.all', 'templates.manage', 'users.manage',
            'departments.manage', 'settings.manage', 'reports.view',
        ],
        'department-head' => ['submissions.view.all', 'approvals.act', 'submissions.create', 'submissions.view.own'],
        'approver' => ['approvals.act'],
        'submitter' => ['submissions.create', 'submissions.view.own', 'submissions.withdraw'],
        'viewer' => ['submissions.view.own'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        collect(self::MATRIX)
            ->flatten()
            ->unique()
            ->each(fn (string $name) => Permission::findOrCreate($name));

        foreach (self::ROLES as $roleName) {
            Role::findOrCreate($roleName)->syncPermissions(self::MATRIX[$roleName] ?? []);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
