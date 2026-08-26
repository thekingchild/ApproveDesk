<?php

namespace Tests\Feature\Admin;

use App\Models\SystemOwner;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_can_manage_custom_roles_from_roles_page(): void
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        $this->actingAs($owner)->get(route('admin.roles.index'))->assertOk()->assertSee('Roles & permissions');

        Livewire::actingAs($owner)->test('pages::admin.roles')
            ->call('createRole')
            ->set('label', 'Finance reviewer')
            ->set('description', 'Can review finance reports.')
            ->set('permissions', ['reports.view'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('role_profiles', ['label' => 'Finance reviewer', 'is_archived' => false]);
    }

    public function test_user_without_role_management_access_cannot_open_page(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('submitter'))
            ->get(route('admin.roles.index'))
            ->assertForbidden();
    }

    public function test_administrator_can_view_roles_but_cannot_create_them(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->get(route('admin.roles.index'))->assertOk();

        Livewire::actingAs($admin)
            ->test('pages::admin.roles')
            ->call('createRole')
            ->assertForbidden();
    }
}
