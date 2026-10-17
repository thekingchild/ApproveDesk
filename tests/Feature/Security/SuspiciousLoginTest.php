<?php

namespace Tests\Feature\Security;

use App\Models\LoginEvent;
use App\Models\User;
use App\Notifications\SuspiciousLoginNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SuspiciousLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_first_sign_in_is_recorded_without_a_notice(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame(1, LoginEvent::query()->where('user_id', $user->id)->count());
        $this->assertFalse((bool) LoginEvent::query()->where('user_id', $user->id)->first()?->was_new_device);
        Notification::assertNothingSent();
    }

    public function test_a_new_device_sends_a_suspicious_login_notice(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->post(route('logout'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeader('User-Agent', 'Unexpected Browser/1.0')
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertTrue(LoginEvent::query()->where('user_id', $user->id)->where('was_new_device', true)->exists());
        Notification::assertSentTo($user, SuspiciousLoginNotification::class);
    }

    public function test_rv_lar_06_the_new_device_notice_is_queued_not_sent_during_sign_in(): void
    {
        $user = User::factory()->create();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('logout'));

        Queue::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeader('User-Agent', 'Unexpected Browser/1.0')
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job): bool => $job->notification instanceof SuspiciousLoginNotification,
        );
    }
}
