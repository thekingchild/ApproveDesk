<?php

namespace Tests\Feature\Notifications;

use App\Actions\Settings\SaveUserChannelIdentityAction;
use App\Enums\NotificationChannelKey;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RV-SEC-10 residual: remapping someone else's chat identity follows the
 * identity rules, and every change is logged.
 */
class ChannelIdentityMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_channel_manager_cannot_remap_an_administrators_slack_identity(): void
    {
        $role = Role::create(['name' => 'channel-manager', 'guard_name' => 'web']);
        $role->givePermissionTo(['notification_channels.manage', 'users.update', 'users.view']);
        $manager = User::factory()->create()->assignRole($role);
        $admin = User::factory()->create(['slack_user_id' => 'U0ADMIN'])->assignRole('admin');

        $this->expectException(InvalidArgumentException::class);

        try {
            app(SaveUserChannelIdentityAction::class)->execute($admin, NotificationChannelKey::Slack, 'U0ATTACKER', $manager);
        } finally {
            $this->assertSame('U0ADMIN', $admin->fresh()->slack_user_id);
        }
    }

    public function test_administrator_can_map_an_ordinary_user_and_the_change_is_logged(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $user = User::factory()->create()->assignRole('submitter');

        app(SaveUserChannelIdentityAction::class)->execute($user, NotificationChannelKey::Slack, 'U0USER', $admin);

        $this->assertSame('U0USER', $user->fresh()->slack_user_id);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'channel-identity-changed',
            'subject_id' => $user->id,
            'causer_id' => $admin->id,
        ]);
    }
}
