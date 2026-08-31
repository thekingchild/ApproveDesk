<?php

namespace Tests\Unit\Services\Users;

use App\Models\Role;
use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Roles\RolePermissionService;
use App\Services\Users\GrantableRoleService;
use App\Services\Users\SystemOwnerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrantableRoleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_can_grant_admin_and_ordinary_roles_but_never_super_admin(): void
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        $roles = app(GrantableRoleService::class)->for($owner);

        $this->assertContains('admin', $roles);
        $this->assertContains('approver', $roles);
        $this->assertNotContains('super-admin', $roles);
    }

    public function test_delegated_admin_can_grant_only_ordinary_roles(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $roles = app(GrantableRoleService::class)->for($admin);

        $this->assertContains('approver', $roles);
        $this->assertNotContains('admin', $roles);
        $this->assertNotContains('super-admin', $roles);
    }

    public function test_users_update_actor_cannot_see_department_head_when_its_permissions_exceed_the_ceiling(): void
    {
        $editor = User::factory()->create();
        $editor->givePermissionTo(['users.update', 'users.view']);

        $roles = app(GrantableRoleService::class)->for($editor);

        $this->assertNotContains('department-head', $roles);
        $this->assertNotContains('admin', $roles);
        $this->assertNotContains('super-admin', $roles);
    }

    public function test_delegated_editor_can_grant_a_built_in_role_only_when_every_permission_is_within_the_ceiling(): void
    {
        $editor = User::factory()->create();
        $editor->givePermissionTo(['users.update', 'users.view', 'submissions.view.own']);

        $roles = app(GrantableRoleService::class)->for($editor);

        $this->assertContains('viewer', $roles);
        $this->assertNotContains('department-head', $roles);
        $this->assertNotContains('approver', $roles);
        $this->assertNotContains('submitter', $roles);
    }

    public function test_custom_and_built_in_roles_use_the_same_permission_ceiling(): void
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        app(SystemOwnerService::class)->forget();

        $inside = app(RolePermissionService::class)->create($owner, 'Own submissions', null, ['submissions.view.own']);
        $outside = app(RolePermissionService::class)->create($owner, 'All submissions', null, ['submissions.view.all']);

        $editor = User::factory()->create();
        $editor->givePermissionTo(['users.update', 'users.view', 'roles.assign.custom', 'submissions.view.own']);

        $roles = app(GrantableRoleService::class)->for($editor);

        $this->assertContains('viewer', $roles);
        $this->assertContains($inside->name, $roles);
        $this->assertNotContains($outside->name, $roles);
        $this->assertNotContains('department-head', $roles);
        $this->assertInstanceOf(Role::class, $inside);
    }

    public function test_administrator_remains_owner_only_and_super_admin_is_never_grantable(): void
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        app(SystemOwnerService::class)->forget();

        $admin = User::factory()->create()->assignRole('admin');
        $editor = User::factory()->create();
        $editor->givePermissionTo(['users.update', 'users.view']);

        $this->assertContains('admin', app(GrantableRoleService::class)->for($owner));
        $this->assertNotContains('super-admin', app(GrantableRoleService::class)->for($owner));
        $this->assertNotContains('admin', app(GrantableRoleService::class)->for($admin));
        $this->assertNotContains('super-admin', app(GrantableRoleService::class)->for($admin));
        $this->assertNotContains('admin', app(GrantableRoleService::class)->for($editor));
        $this->assertNotContains('super-admin', app(GrantableRoleService::class)->for($editor));
    }
}
