<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_ordinary_users_cannot_manage_departments(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)
            ->get(route('admin.departments.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_a_department_with_parent_and_manager(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $parent = Department::factory()->create(['name' => 'Operations']);
        $manager = User::factory()->create(['name' => 'Fatima Manager']);

        Livewire::actingAs($admin)
            ->test('pages::admin.departments')
            ->set('name', 'Procurement')
            ->set('parentId', (string) $parent->id)
            ->set('managerId', (string) $manager->id)
            ->call('save')
            ->assertHasNoErrors();

        $department = Department::query()->where('name', 'Procurement')->firstOrFail();

        $this->assertSame($parent->id, $department->parent_id);
        $this->assertSame($manager->id, $department->manager_id);
    }

    public function test_admin_can_edit_department_relationships(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $parent = Department::factory()->create();
        $manager = User::factory()->create();
        $department = Department::factory()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.departments')
            ->call('editDepartment', $department->id)
            ->set('name', 'Finance and Treasury')
            ->set('parentId', (string) $parent->id)
            ->set('managerId', (string) $manager->id)
            ->call('save')
            ->assertHasNoErrors();

        $department->refresh();

        $this->assertSame('Finance and Treasury', $department->name);
        $this->assertSame($parent->id, $department->parent_id);
        $this->assertSame($manager->id, $department->manager_id);
    }

    public function test_department_cannot_be_its_own_parent(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.departments')
            ->call('editDepartment', $department->id)
            ->set('parentId', (string) $department->id)
            ->call('save')
            ->assertHasErrors(['parentId']);

        $this->assertNull($department->fresh()->parent_id);
    }

    public function test_department_cannot_be_moved_below_one_of_its_descendants(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $parent = Department::factory()->create(['name' => 'Parent']);
        $child = Department::factory()->create([
            'name' => 'Child',
            'parent_id' => $parent->id,
        ]);

        Livewire::actingAs($admin)
            ->test('pages::admin.departments')
            ->call('editDepartment', $parent->id)
            ->set('parentId', (string) $child->id)
            ->call('save')
            ->assertHasErrors(['parentId']);

        $this->assertNull($parent->fresh()->parent_id);
        $this->assertSame($parent->id, $child->fresh()->parent_id);
    }

    public function test_department_list_shows_manager_and_member_count(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create(['name' => 'Legal']);
        $manager = User::factory()->create([
            'name' => 'Legal Manager',
            'department_id' => $department->id,
        ]);
        User::factory()->create(['department_id' => $department->id]);
        $department->update(['manager_id' => $manager->id]);

        Livewire::actingAs($admin)
            ->test('pages::admin.departments')
            ->assertSee('Legal')
            ->assertSee('Legal Manager')
            ->assertSee('2');
    }
}
