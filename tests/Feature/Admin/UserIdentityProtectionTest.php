<?php

namespace Tests\Feature\Admin;

use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Roles\RoleAssignmentService;
use App\Services\Roles\RolePermissionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-SEC-04: administrators cannot take over or wipe accounts with more access
 * than their own by changing the email, deactivating, or anonymizing them.
 */
class UserIdentityProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $this->owner->id]);
    }

    public function test_admin_cannot_change_the_email_of_someone_with_more_access(): void
    {
        $victim = $this->userAboveAdminCeiling();
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('admin.users.user-form')
            ->call('edit', $victim->id)
            ->assertSeeHtml('data-test="email-locked"')
            ->set('email', 'attacker@example.com')
            ->call('save')
            ->assertHasErrors('email');

        $this->assertSame('victim@example.com', $victim->fresh()->email);
    }

    public function test_admin_can_still_edit_ordinary_details_of_someone_with_more_access(): void
    {
        $victim = $this->userAboveAdminCeiling();
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('admin.users.user-form')
            ->call('edit', $victim->id)
            ->set('name', 'Renamed Steward')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed Steward', $victim->fresh()->name);
    }

    public function test_admin_can_change_the_email_of_an_ordinary_user(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com'])->assignRole('submitter');
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('admin.users.user-form')
            ->call('edit', $user->id)
            ->set('email', 'new@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_admin_cannot_anonymize_or_deactivate_a_peer_administrator(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $peer = User::factory()->create()->assignRole('admin');

        $this->assertFalse($admin->can('anonymize', $peer));
        $this->assertFalse($admin->can('activate', $peer));

        Livewire::actingAs($admin)
            ->test('admin.users.user-form')
            ->call('edit', $peer->id)->call('anonymize')
            ->assertForbidden();

        $this->assertFalse($peer->fresh()->isAnonymized());
        $this->assertTrue($peer->fresh()->is_active);
    }

    public function test_system_owner_keeps_full_identity_control(): void
    {
        $victim = $this->userAboveAdminCeiling();
        $peer = User::factory()->create()->assignRole('admin');

        $this->assertTrue($this->owner->can('manageIdentity', $victim));
        $this->assertTrue($this->owner->can('anonymize', $peer));
    }

    private function userAboveAdminCeiling(): User
    {
        $steward = app(RolePermissionService::class)->create($this->owner, 'Role steward', null, ['roles.view', 'roles.manage.custom']);
        $victim = User::factory()->create(['email' => 'victim@example.com']);
        app(RoleAssignmentService::class)->sync($this->owner, $victim, [$steward->name]);

        return $victim->fresh();
    }
}
