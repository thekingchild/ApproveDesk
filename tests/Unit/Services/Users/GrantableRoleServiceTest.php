<?php

namespace Tests\Unit\Services\Users;

use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Users\GrantableRoleService;
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
}
