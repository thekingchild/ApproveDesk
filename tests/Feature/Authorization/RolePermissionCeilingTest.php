<?php

namespace Tests\Feature\Authorization;

use App\Actions\Users\UpdateUserAction;
use App\DTOs\UserData;
use App\Models\Role;
use App\Models\SystemOwner;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Roles\RoleAssignmentService;
use App\Services\Roles\RolePermissionService;
use App\Services\Users\UserImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RolePermissionCeilingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_can_create_edit_clone_archive_and_restore_custom_roles(): void
    {
        $owner = $this->owner();
        $service = app(RolePermissionService::class);

        $role = $service->create($owner, 'Finance reviewer', 'Reviews finance requests.', ['reports.view']);
        $this->assertTrue($role->hasPermissionTo('reports.view'));

        $service->update($owner, $role, 'Senior finance reviewer', null, ['reports.view', 'reports.export']);
        $clone = $service->clone($owner, $role, 'Finance reviewer copy');
        $service->archive($owner, $role);
        $this->assertTrue($role->profile->fresh()->is_archived);
        $service->restore($owner, $role);

        $this->assertFalse($role->profile->fresh()->is_archived);
        $this->assertTrue($clone->hasPermissionTo('reports.export'));
        $this->assertDatabaseCount('role_change_events', 5);
    }

    public function test_role_manager_is_limited_to_delegable_permissions_they_hold(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['roles.manage.custom', 'reports.view']);

        $role = app(RolePermissionService::class)->create($manager, 'Report reader', null, ['reports.view']);
        $this->assertTrue($role->hasPermissionTo('reports.view'));

        $this->expectException(ValidationException::class);
        app(RolePermissionService::class)->update($manager, $role, 'Escalated', null, ['reports.export']);
    }

    public function test_role_manager_cannot_grant_role_manager_authority_or_edit_built_ins(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['roles.manage.custom', 'roles.assign.custom']);

        try {
            app(RolePermissionService::class)->create($manager, 'Role manager', null, ['roles.manage.custom']);
            $this->fail('Expected permission ceiling validation failure.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->expectException(ValidationException::class);
        app(RolePermissionService::class)->update($manager, Role::findByName('approver'), 'Changed', null, ['approvals.act']);
    }

    public function test_assignment_service_blocks_archived_roles_and_non_owner_administrator_assignment(): void
    {
        $owner = $this->owner();
        $manager = User::factory()->create();
        $manager->givePermissionTo(['roles.manage.custom', 'roles.assign.custom', 'reports.view']);
        $target = User::factory()->create()->assignRole('viewer');
        $role = app(RolePermissionService::class)->create($manager, 'Report reader', null, ['reports.view']);

        app(RoleAssignmentService::class)->sync($manager, $target, [$role->name]);
        $this->assertTrue($target->fresh()->hasRole($role));

        app(RolePermissionService::class)->archive($manager, $role);
        $this->expectException(ValidationException::class);
        app(RoleAssignmentService::class)->sync($manager, $target, [$role->name, 'admin']);
    }

    public function test_import_create_rejects_roles_the_actor_cannot_grant(): void
    {
        $importer = User::factory()->create();
        $importer->givePermissionTo(['users.import', 'users.create', 'users.view']);

        $this->expectException(ValidationException::class);
        app(UserImportService::class)->commit([
            [
                'status' => 'ready',
                'name' => 'Pat Tamper',
                'email' => 'pat@example.com',
                'department' => '',
                'manager_email' => '',
                'phone' => '',
                'roles' => 'auditor',
            ],
        ], $importer);
    }

    public function test_updating_a_user_keeps_an_archived_role_the_editor_cannot_grant(): void
    {
        $owner = $this->owner();
        $admin = User::factory()->create()->assignRole('admin');
        $role = app(RolePermissionService::class)->create($owner, 'Seasonal reviewer', null, ['reports.view']);
        $target = User::factory()->create()->assignRole(['viewer', $role->name]);
        app(RolePermissionService::class)->archive($owner, $role);

        app(UpdateUserAction::class)->execute(
            $target,
            new UserData(
                name: $target->name,
                email: $target->email,
                phone: $target->phone,
                departmentId: $target->department_id,
                roles: ['viewer'],
                isActive: true,
            ),
            $admin,
        );

        $this->assertTrue($target->fresh()->hasRole($role->name));
        $this->assertTrue($target->fresh()->hasRole('viewer'));
    }

    public function test_owner_cannot_put_custom_code_permission_on_a_custom_role(): void
    {
        $owner = $this->owner();

        $this->expectException(ValidationException::class);
        app(RolePermissionService::class)->create($owner, 'Code editor', null, ['branding.manage', 'branding.custom_code.manage']);
    }

    public function test_users_update_actor_cannot_assign_department_head_above_ceiling(): void
    {
        $editor = User::factory()->create();
        $editor->givePermissionTo(['users.update', 'users.view']);
        $target = User::factory()->create()->assignRole('viewer');

        try {
            app(RoleAssignmentService::class)->sync($editor, $target, ['department-head']);
            $this->fail('Expected permission ceiling validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('roles', $exception->errors());
        }

        $this->assertTrue($target->fresh()->hasRole('viewer'));
        $this->assertFalse($target->fresh()->hasRole('department-head'));
    }

    public function test_non_owner_cannot_add_or_remove_own_roles_via_assignment_service(): void
    {
        $editor = User::factory()->create()->assignRole('viewer');
        $editor->givePermissionTo(['users.update', 'users.view']);

        try {
            app(RoleAssignmentService::class)->sync($editor, $editor, ['viewer', 'approver']);
            $this->fail('Expected self-assignment validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('roles', $exception->errors());
        }

        $this->assertTrue($editor->fresh()->hasRole('viewer'));
        $this->assertFalse($editor->fresh()->hasRole('approver'));
    }

    public function test_non_owner_cannot_remove_one_of_their_own_roles_via_assignment_service(): void
    {
        $editor = User::factory()->create()->assignRole(['viewer', 'approver']);
        $editor->givePermissionTo(['users.update', 'users.view']);

        try {
            app(RoleAssignmentService::class)->sync($editor, $editor, ['viewer']);
            $this->fail('Expected self-removal validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('roles', $exception->errors());
        }

        $editor->refresh();
        $this->assertTrue($editor->hasRole('viewer'));
        $this->assertTrue($editor->hasRole('approver'));
    }

    public function test_assign_roles_policy_preserves_owner_bypass_and_blocks_non_owner_self_or_owner_targets(): void
    {
        $owner = $this->owner();
        $editor = User::factory()->create();
        $editor->givePermissionTo('users.update');
        $target = User::factory()->create();
        $policy = app(UserPolicy::class);

        $this->assertTrue($policy->assignRoles($owner, $owner));
        $this->assertTrue($policy->assignRoles($owner, $target));
        $this->assertFalse($policy->assignRoles($editor, $editor));
        $this->assertFalse($policy->assignRoles($editor, $owner));
        $this->assertTrue($policy->assignRoles($editor, $target));
    }

    public function test_non_owner_self_profile_edit_cannot_change_roles_via_update_action(): void
    {
        $editor = User::factory()->create()->assignRole('viewer');
        $editor->givePermissionTo(['users.update']);

        try {
            app(UpdateUserAction::class)->execute(
                $editor,
                new UserData(
                    name: $editor->name,
                    email: $editor->email,
                    phone: $editor->phone,
                    departmentId: $editor->department_id,
                    roles: ['approver'],
                    isActive: true,
                ),
                $editor,
            );
            $this->fail('Expected self-assignment validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('roles', $exception->errors());
        }

        $this->assertTrue($editor->fresh()->hasRole('viewer'));
        $this->assertFalse($editor->fresh()->hasRole('approver'));
    }

    public function test_import_create_rejects_department_head_when_actor_ceiling_is_users_update_only(): void
    {
        $importer = User::factory()->create();
        $importer->givePermissionTo(['users.import', 'users.create', 'users.update', 'users.view']);

        try {
            app(UserImportService::class)->commit([
                [
                    'status' => 'ready',
                    'name' => 'Pat Head',
                    'email' => 'pat.head@example.com',
                    'department' => '',
                    'manager_email' => '',
                    'phone' => '',
                    'roles' => 'department-head',
                ],
            ], $importer);
            $this->fail('Expected permission ceiling validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('roles', $exception->errors());
        }

        $this->assertDatabaseMissing('users', ['email' => 'pat.head@example.com']);
    }

    private function owner(): User
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        return $owner;
    }
}
