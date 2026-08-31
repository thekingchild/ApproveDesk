<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\SystemOwner;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_ordinary_users_cannot_open_user_management(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_a_user_with_role_and_department(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->set('name', 'Amina Bello')
            ->set('email', 'amina@example.test')
            ->set('departmentId', (string) $department->id)
            ->set('roles', ['approver'])
            ->set('password', 'Secure-password-123')
            ->set('password_confirmation', 'Secure-password-123')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'amina@example.test')->firstOrFail();

        $this->assertSame('Amina Bello', $user->name);
        $this->assertSame($department->id, $user->department_id);
        $this->assertTrue($user->hasRole('approver'));
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Secure-password-123', $user->password));
        $this->assertNull($user->phone);
    }

    public function test_admin_can_save_an_e164_phone_number_and_rejects_local_formats(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->set('name', 'Kemi SMS')
            ->set('email', 'kemi@example.test')
            ->set('phone', '08012345678')
            ->set('roles', ['approver'])
            ->set('password', 'Secure-password-123')
            ->set('password_confirmation', 'Secure-password-123')
            ->call('save')
            ->assertHasErrors(['phone']);

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->set('name', 'Kemi SMS')
            ->set('email', 'kemi@example.test')
            ->set('phone', '+234 801 234 5678')
            ->set('roles', ['approver'])
            ->set('password', 'Secure-password-123')
            ->set('password_confirmation', 'Secure-password-123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('+2348012345678', User::query()->where('email', 'kemi@example.test')->value('phone'));
    }

    public function test_admin_can_update_roles_department_and_account_status(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create();
        $user = User::factory()->create()->assignRole('submitter');

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->call('editUser', $user->id)
            ->set('departmentId', (string) $department->id)
            ->set('roles', ['approver'])
            ->set('isActive', false)
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame($department->id, $user->department_id);
        $this->assertFalse($user->is_active);
        $this->assertTrue($user->hasRole('approver'));
        $this->assertFalse($user->hasRole('submitter'));
    }

    public function test_admin_cannot_grant_the_super_admin_role(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->set('name', 'Privilege Escalation')
            ->set('email', 'elevated@example.test')
            ->set('roles', ['super-admin'])
            ->set('password', 'Secure-password-123')
            ->set('password_confirmation', 'Secure-password-123')
            ->call('save')
            ->assertHasErrors(['roles']);

        $this->assertDatabaseMissing('users', ['email' => 'elevated@example.test']);
    }

    public function test_final_super_admin_cannot_remove_their_own_access(): void
    {
        $superAdmin = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create([
            'id' => SystemOwner::SINGLETON_ID,
            'user_id' => $superAdmin->id,
        ]);

        Livewire::actingAs($superAdmin)
            ->test('pages::admin.users')
            ->call('editUser', $superAdmin->id)
            ->assertForbidden();

        $this->assertTrue($superAdmin->fresh()->hasRole('super-admin'));
        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_admin_cannot_change_an_existing_password_through_user_edit(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $user = User::factory()->create(['password' => 'original-password'])->assignRole('approver');

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->call('editUser', $user->id)
            ->set('password', 'forged-new-password')
            ->set('password_confirmation', 'forged-new-password')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
        $this->assertFalse(Hash::check('forged-new-password', $user->fresh()->password));
    }

    public function test_inactive_user_cannot_log_in_or_keep_an_existing_session(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_non_owner_cannot_forge_a_self_role_change_through_livewire(): void
    {
        $editor = User::factory()->create()->assignRole('viewer');
        $editor->givePermissionTo(['users.update', 'users.view']);

        $component = Livewire::actingAs($editor)
            ->test('pages::admin.users')
            ->call('editUser', $editor->id)
            ->set('roles', ['approver'])
            ->call('save');

        try {
            $component->assertHasErrors(['roles']);
        } catch (ExpectationFailedException) {
            $component->assertForbidden();
        }

        $this->assertTrue($editor->fresh()->hasRole('viewer'));
        $this->assertFalse($editor->fresh()->hasRole('approver'));
    }

    public function test_users_update_actor_cannot_assign_department_head_through_livewire(): void
    {
        $editor = User::factory()->create();
        $editor->givePermissionTo(['users.update', 'users.view']);
        $target = User::factory()->create()->assignRole('viewer');

        Livewire::actingAs($editor)
            ->test('pages::admin.users')
            ->call('editUser', $target->id)
            ->set('roles', ['department-head'])
            ->call('save')
            ->assertHasErrors(['roles']);

        $this->assertTrue($target->fresh()->hasRole('viewer'));
        $this->assertFalse($target->fresh()->hasRole('department-head'));
    }
}
