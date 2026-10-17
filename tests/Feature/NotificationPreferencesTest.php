<?php

namespace Tests\Feature;

use App\Enums\NotificationFrequency;
use App\Jobs\SendNotificationDigests;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\NotificationDigest;
use App\Services\Notifications\DigestSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_choose_daily_digest_delivery(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.notifications')
            ->set('frequency', NotificationFrequency::Daily->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(NotificationFrequency::Daily, $user->fresh()->notification_frequency);
    }

    public function test_digest_users_receive_database_notifications_without_immediate_mail(): void
    {
        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::Daily]);
        $notification = new ActionRequiredNotification(Submission::factory()->create());

        $this->assertSame(['database'], $notification->via($user));

        $user->update(['notification_frequency' => NotificationFrequency::Immediate]);
        $this->assertSame(['mail', 'database'], $notification->via($user->fresh()));
    }

    public function test_digest_job_groups_pending_database_notifications_and_tracks_delivery(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'notification_frequency' => NotificationFrequency::Daily,
            'last_notification_digest_at' => now()->subDays(2),
        ]);
        DatabaseNotification::query()->create([
            'id' => fake()->uuid(),
            'type' => ActionRequiredNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['title' => 'Budget memo', 'reference_number' => 'SUB-2026-00123'],
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        app(SendNotificationDigests::class)->handle(app(DigestSchedule::class));

        Notification::assertSentTo(
            $user,
            NotificationDigest::class,
            fn (NotificationDigest $notification) => count($notification->items) === 1,
        );
        $this->assertNotNull($user->fresh()->last_notification_digest_at);
    }
}
