<?php

namespace Tests\Feature;

use App\Enums\NotificationFrequency;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\ActionRequiredNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_centre_shows_unread_state_and_recorded_delivery_mode(): void
    {
        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::Daily]);
        $submission = Submission::factory()->for($user, 'submitter')->create(['title' => 'Budget renewal']);
        $notification = $this->notification($user, $submission, NotificationFrequency::Daily);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Budget renewal')
            ->assertSee('1 unread notification')
            ->assertSee('In app + daily digest')
            ->assertSee('Delivery preferences');

        Livewire::actingAs($user)
            ->test('pages::notifications.index')
            ->call('markRead', $notification->id)
            ->assertDispatched('notifications-changed');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_only_updates_the_authenticated_users_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $submission = Submission::factory()->for($user, 'submitter')->create();
        $own = $this->notification($user, $submission);
        $otherNotification = $this->notification($other, $submission);

        Livewire::actingAs($user)
            ->test('pages::notifications.index')
            ->call('markAllRead');

        $this->assertNotNull($own->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_open_notification_marks_it_read_and_uses_an_authorized_deep_link(): void
    {
        $user = User::factory()->create();
        $submission = Submission::factory()->for($user, 'submitter')->create();
        $notification = $this->notification($user, $submission);

        Livewire::actingAs($user)
            ->test('pages::notifications.index')
            ->call('openNotification', $notification->id)
            ->assertRedirect(route('submissions.show', $submission));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_notification_does_not_offer_a_deep_link_to_a_denied_submission(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $submission = Submission::factory()->for($other, 'submitter')->create();
        $this->notification($user, $submission);

        Livewire::actingAs($user)
            ->test('pages::notifications.index')
            ->assertDontSee('Open request');
    }

    private function notification(
        User $user,
        Submission $submission,
        NotificationFrequency $frequency = NotificationFrequency::Immediate,
    ): DatabaseNotification {
        return DatabaseNotification::query()->create([
            'id' => fake()->uuid(),
            'type' => ActionRequiredNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'kind' => 'action_required',
                'submission_id' => $submission->id,
                'reference_number' => $submission->reference_number,
                'title' => $submission->title,
                'delivery_frequency' => $frequency->value,
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
