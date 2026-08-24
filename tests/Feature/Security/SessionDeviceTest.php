<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\UserSession;
use App\Services\Security\DeviceSessionManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

class SessionDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_other_sessions_can_be_revoked_without_ending_this_one(): void
    {
        $user = User::factory()->create()->assignRole('submitter');
        $this->actingAs($user);
        Session::start();
        $current = Session::getId();

        UserSession::query()->create([
            'id' => $current,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit Current',
            'payload' => '',
            'last_activity' => time(),
        ]);
        UserSession::query()->create([
            'id' => 'other-session-id',
            'user_id' => $user->id,
            'ip_address' => '10.0.0.8',
            'user_agent' => 'Mozilla/5.0 Firefox/120.0',
            'payload' => '',
            'last_activity' => time() - 60,
        ]);

        $removed = app(DeviceSessionManager::class)->revokeOthers($user, $current);

        $this->assertSame(1, $removed);
        $this->assertTrue(UserSession::query()->whereKey($current)->exists());
        $this->assertFalse(UserSession::query()->whereKey('other-session-id')->exists());
    }

    public function test_security_page_lists_devices_after_password_confirmation(): void
    {
        $user = User::factory()->create()->assignRole('submitter');
        UserSession::query()->create([
            'id' => 'listed-session',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0 Chrome/120.0',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Devices')
            ->assertSee('Chrome')
            ->assertSee('203.0.113.10');

        Livewire::actingAs($user)
            ->test('pages::settings.security')
            ->call('revokeDevice', 'listed-session');

        $this->assertFalse(UserSession::query()->whereKey('listed-session')->exists());
    }
}
