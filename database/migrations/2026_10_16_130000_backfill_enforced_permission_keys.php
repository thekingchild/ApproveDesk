<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RV-SEC-13: six catalogue permissions become enforced in v2.8.7. Before the
 * policies check them, grant each one to every existing role (built-in and
 * custom) that effectively has the ability today, so nobody loses access on
 * upgrade. Idempotent; never removes anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(Role::class)) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['submissions.view.own', 'submissions.withdraw', 'comments.create', 'reminders.send', 'audit.evidence.export', 'audit.auditor_access.manage'] as $key) {
            Permission::findOrCreate($key, 'web');
        }

        foreach (Role::query()->with('permissions')->get() as $role) {
            $held = $role->permissions->pluck('name')->all();
            $has = fn (string ...$any): bool => array_intersect($any, $held) !== [];
            $grant = [];

            if ($has('submissions.create')) {
                $grant[] = 'submissions.view.own';
                $grant[] = 'submissions.withdraw';
            }

            // Anyone who could open a request could comment and remind, except read-only auditors.
            if ($role->name !== 'auditor') {
                $grant[] = 'comments.create';
                $grant[] = 'reminders.send';
            }

            if ($has('submissions.view.all', 'settings.manage') || $role->name === 'auditor') {
                $grant[] = 'audit.evidence.export';
            }

            if ($has('settings.manage')) {
                $grant[] = 'audit.auditor_access.manage';
            }

            $missing = array_values(array_diff(array_unique($grant), $held));
            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants are additive and harmless to keep; nothing to undo.
    }
};
