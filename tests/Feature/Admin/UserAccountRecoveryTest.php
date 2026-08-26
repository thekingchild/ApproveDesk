<?php

namespace Tests\Feature\Admin;

use App\Models\AccountRecoveryEvent;
use App\Models\SystemOwner;
use App\Models\User;
use App\Models\UserSession;
use App\Notifications\AccountRecoveryInitiatedNotification;
use App\Support\SecondFactorAssurance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class UserAccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_can_invalidate_password_and_send_reset_link(): void
    {
        Notification::fake();
        [$owner, $target] = $this->ownerAndTarget();

        $this->actingAs($owner)
            ->withSession($this->ownerSession($owner))
            ->post(route('admin.users.recovery.password', $target))
            ->assertRedirect(route('admin.users.index'));

        $this->assertFalse(Hash::check('password', $target->fresh()->password));
        Notification::assertSentTo($target, ResetPassword::class);
        Notification::assertSentTo($target, AccountRecoveryInitiatedNotification::class);
        $this->assertSame(0, UserSession::query()->where('user_id', $target->id)->count());
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $target->id)->count());
        $this->assertDatabaseHas('account_recovery_events', [
            'actor_id' => $owner->id,
            'target_id' => $target->id,
            'action' => AccountRecoveryEvent::ACTION_PASSWORD_RESET,
        ]);
        $this->assertTrue($target->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_failed_reset_email_leaves_password_unchanged(): void
    {
        Notification::fake();
        [$owner, $target] = $this->ownerAndTarget();
        Password::shouldReceive('sendResetLink')->once()->andReturn(Password::RESET_THROTTLED);

        $this->actingAs($owner)
            ->withSession($this->ownerSession($owner))
            ->from(route('admin.users.index'))
            ->post(route('admin.users.recovery.password', $target))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('user');

        $this->assertTrue(Hash::check('password', $target->fresh()->password));
        $this->assertDatabaseMissing('account_recovery_events', [
            'target_id' => $target->id,
            'action' => AccountRecoveryEvent::ACTION_PASSWORD_RESET,
        ]);
    }

    public function test_owner_can_clear_second_factor_and_passkeys(): void
    {
        Notification::fake();
        [$owner, $target] = $this->ownerAndTarget();
        $target->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => 'cred-target-1',
            'credential' => ['id' => 'cred-target-1'],
        ]);

        $this->actingAs($owner)
            ->withSession($this->ownerSession($owner))
            ->post(route('admin.users.recovery.second-factor', $target))
            ->assertRedirect(route('admin.users.index'));

        $fresh = $target->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertSame(0, $fresh->passkeys()->count());
        $this->assertTrue(Hash::check('password', $fresh->password));
        $this->assertDatabaseHas('account_recovery_events', [
            'target_id' => $target->id,
            'action' => AccountRecoveryEvent::ACTION_SECOND_FACTOR_RESET,
        ]);
        Notification::assertSentTo($target, AccountRecoveryInitiatedNotification::class);
    }

    public function test_admin_cannot_recover_another_account(): void
    {
        [, $target] = $this->ownerAndTarget();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.users.recovery.password', $target))
            ->assertForbidden();
    }

    public function test_owner_cannot_recover_the_owner_account(): void
    {
        [$owner] = $this->ownerAndTarget();

        $this->actingAs($owner)
            ->withSession($this->ownerSession($owner))
            ->post(route('admin.users.recovery.password', $owner))
            ->assertForbidden();
    }

    public function test_recovery_requires_password_confirmation_and_second_factor(): void
    {
        [$owner, $target] = $this->ownerAndTarget();

        $this->actingAs($owner)
            ->post(route('admin.users.recovery.second-factor', $target))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.users.recovery.second-factor', $target))
            ->assertForbidden();
    }

    public function test_recovery_events_cannot_be_updated_or_deleted(): void
    {
        [$owner, $target] = $this->ownerAndTarget();
        $event = AccountRecoveryEvent::query()->create([
            'actor_id' => $owner->id,
            'target_id' => $target->id,
            'action' => AccountRecoveryEvent::ACTION_PASSWORD_RESET,
            'metadata' => ['result' => 'sent'],
        ]);

        $this->expectException(\RuntimeException::class);
        $event->update(['action' => 'mutated']);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function ownerAndTarget(): array
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        $owner->passkeys()->create([
            'name' => 'Owner key',
            'credential_id' => 'cred-owner-1',
            'credential' => ['id' => 'cred-owner-1'],
        ]);
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        $target = User::factory()->withTwoFactor()->create();
        $target->assignRole('approver');
        UserSession::query()->create([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => time(),
        ]);
        $target->createToken('api');

        return [$owner, $target];
    }

    /** @return array<string, mixed> */
    private function ownerSession(User $owner): array
    {
        return [
            'auth.password_confirmed_at' => time(),
            SecondFactorAssurance::SESSION_KEY => [
                'user_id' => (string) $owner->id,
                'verified_at' => time(),
                'method' => 'passkey',
            ],
        ];
    }
}
