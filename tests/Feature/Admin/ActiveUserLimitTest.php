<?php

namespace Tests\Feature\Admin;

use App\Actions\Auth\ResolveOAuthUserAction;
use App\Actions\Users\CreateUserAction;
use App\DTOs\OAuthUserData;
use App\DTOs\UserData;
use App\Enums\OAuthProvider;
use App\Models\SiteLimit;
use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Users\UserImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ActiveUserLimitTest extends TestCase
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

    public function test_default_limit_is_unlimited(): void
    {
        $this->assertNull(SiteLimit::query()->findOrFail(SiteLimit::SINGLETON_ID)->max_active_users);
        $this->createUser('open@example.test', true);
        $this->assertTrue(User::query()->where('email', 'open@example.test')->exists());
    }

    public function test_owner_is_counted_and_last_slot_blocks_further_activation(): void
    {
        SiteLimit::query()->whereKey(SiteLimit::SINGLETON_ID)->update(['max_active_users' => 2]);

        $this->createUser('last@example.test', true);
        $this->assertTrue(User::query()->where('email', 'last@example.test')->exists());

        try {
            $this->createUser('over@example.test', true);
            $this->fail('Expected capacity rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('isActive', $exception->errors());
        }

        $this->assertFalse(User::query()->where('email', 'over@example.test')->exists());
    }

    public function test_inactive_creation_is_allowed_at_capacity(): void
    {
        SiteLimit::query()->whereKey(SiteLimit::SINGLETON_ID)->update(['max_active_users' => 1]);

        $this->createUser('parked@example.test', false);

        $this->assertFalse(User::query()->where('email', 'parked@example.test')->value('is_active'));
    }

    public function test_deactivation_frees_a_slot_and_reactivation_rechecks(): void
    {
        SiteLimit::query()->whereKey(SiteLimit::SINGLETON_ID)->update(['max_active_users' => 2]);
        $held = $this->createUser('held@example.test', true);

        Livewire::actingAs($this->owner)
            ->test('admin.users.user-form')
            ->call('edit', $held->id)
            ->set('isActive', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->createUser('replacement@example.test', true);

        Livewire::actingAs($this->owner)
            ->test('admin.users.user-form')
            ->call('edit', $held->id)
            ->set('isActive', true)
            ->call('save')
            ->assertHasErrors(['isActive']);
    }

    public function test_owner_cannot_lower_limit_below_current_usage(): void
    {
        User::factory()->create()->assignRole('approver');

        Livewire::actingAs($this->owner)
            ->test('admin.users.capacity')
            ->set('unlimitedUsers', false)
            ->set('maxActiveUsers', '1')
            ->call('save')
            ->assertHasErrors(['maxActiveUsers']);
    }

    public function test_admin_cannot_see_or_change_capacity(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->assertDontSee('Active-user capacity');

        Livewire::actingAs($admin)
            ->test('admin.users.capacity')
            ->assertForbidden();
    }

    public function test_registration_and_oauth_and_import_respect_the_limit(): void
    {
        SiteLimit::query()->whereKey(SiteLimit::SINGLETON_ID)->update(['max_active_users' => 1]);

        $this->post(route('register.store'), [
            'name' => 'New Person',
            'email' => 'register@example.test',
            'password' => 'Secure-password-123',
            'password_confirmation' => 'Secure-password-123',
        ])->assertSessionHasErrors();

        try {
            app(ResolveOAuthUserAction::class)->execute(new OAuthUserData(
                provider: OAuthProvider::Google,
                providerUserId: 'google-1',
                name: 'OAuth Person',
                email: 'oauth@example.test',
                avatar: null,
            ));
            $this->fail('Expected OAuth capacity rejection.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $report = app(UserImportService::class)->analyze([[
            'name' => 'Imported',
            'email' => 'import@example.test',
            'department' => '',
            'manager_email' => '',
            'phone' => '',
            'roles' => 'submitter',
        ]], $this->owner);

        $this->assertSame('error', $report[0]['status']);
    }

    public function test_owner_can_set_and_clear_the_limit(): void
    {
        Livewire::actingAs($this->owner)
            ->test('admin.users.capacity')
            ->assertSee('Active-user capacity')
            ->set('unlimitedUsers', false)
            ->set('maxActiveUsers', (string) User::query()->where('is_active', true)->count())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            User::query()->where('is_active', true)->count(),
            SiteLimit::query()->findOrFail(SiteLimit::SINGLETON_ID)->max_active_users,
        );

        Livewire::actingAs($this->owner)
            ->test('admin.users.capacity')
            ->set('unlimitedUsers', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(SiteLimit::query()->findOrFail(SiteLimit::SINGLETON_ID)->max_active_users);
    }

    private function createUser(string $email, bool $active): User
    {
        return app(CreateUserAction::class)->execute(new UserData(
            name: 'Created '.$email,
            email: $email,
            phone: null,
            departmentId: null,
            roles: ['approver'],
            isActive: $active,
            password: 'Secure-password-123',
        ), $this->owner);
    }
}
