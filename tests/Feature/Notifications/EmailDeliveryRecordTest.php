<?php

namespace Tests\Feature\Notifications;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationFrequency;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Jobs\SendNotificationDigests;
use App\Listeners\RecordEmailDelivery;
use App\Models\NotificationDelivery;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\DelegationAssignedNotification;
use App\Notifications\SuspiciousLoginNotification;
use App\Services\Audit\EvidencePackBuilder;
use App\Services\Notifications\DigestSchedule;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use RuntimeException;
use Tests\TestCase;

/** RV-DATA-09: emails about a request are recorded against it, and each goes out once. */
class EmailDeliveryRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approver = User::factory()->create(['notification_frequency' => NotificationFrequency::Immediate]);
        $this->requester = User::factory()->create(['notification_frequency' => NotificationFrequency::Immediate]);
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
    }

    public function test_each_workflow_listener_runs_once(): void
    {
        foreach (Event::getRawListeners() as $event => $listeners) {
            $names = array_map(fn (mixed $listener): string => is_string($listener) ? explode('@', $listener)[0] : spl_object_hash((object) $listener), $listeners);
            $this->assertSame(array_values(array_unique($names)), array_values($names), "{$event} has a listener registered twice.");
        }

        NotificationFacade::fake();
        $delegate = User::factory()->create();
        $instance = $this->submit()->workflowInstance;
        app(WorkflowEngine::class)->delegate($instance, $this->approver, $delegate, now()->addDay());

        NotificationFacade::assertSentToTimes($this->approver, ActionRequiredNotification::class, 1);
        NotificationFacade::assertSentToTimes($delegate, DelegationAssignedNotification::class, 1);
    }

    public function test_emails_about_a_request_are_recorded_against_it(): void
    {
        $submission = $this->submit();
        app(WorkflowEngine::class)->processAction($submission->workflowInstance, $this->approver, WorkflowActionType::Approve);

        $mail = NotificationDelivery::query()->where('channel', 'mail')->where('submission_id', $submission->id)->orderBy('id')->get();

        $this->assertSame(
            [[$this->approver->id, 'action_required'], [$this->requester->id, 'decided']],
            $mail->map(fn (NotificationDelivery $delivery): array => [$delivery->user_id, $delivery->kind])->all(),
        );
        $this->assertTrue($mail->every(fn (NotificationDelivery $delivery): bool => $delivery->status === NotificationDeliveryStatus::Sent && $delivery->sent_at !== null));

        $pack = json_decode(app(EvidencePackBuilder::class)->files($submission->fresh())['notifications.json'], true);
        $this->assertSame(['mail', 'mail'], array_column(array_filter($pack, fn (array $row): bool => $row['channel'] === 'mail'), 'channel'));
    }

    public function test_a_digest_is_recorded_on_each_request_it_mentions(): void
    {
        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::Daily, 'last_notification_digest_at' => now()->subDays(2)]);
        $first = Submission::factory()->create();
        $second = Submission::factory()->create();
        foreach ([$first, $second, $first] as $submission) {
            DatabaseNotification::query()->create([
                'id' => fake()->uuid(),
                'type' => ActionRequiredNotification::class,
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => ['title' => $submission->title, 'reference_number' => $submission->reference_number, 'kind' => 'action_required', 'submission_id' => $submission->id],
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ]);
        }

        app(SendNotificationDigests::class)->handle(app(DigestSchedule::class));

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            NotificationDelivery::query()->where('channel', 'mail')->where('kind', 'digest')->where('user_id', $user->id)->pluck('submission_id')->all(),
        );
    }

    public function test_emails_not_about_a_request_are_not_recorded(): void
    {
        $this->requester->notify(new SuspiciousLoginNotification('203.0.113.9', 'Firefox on Linux', now()));

        $this->assertSame(0, NotificationDelivery::query()->where('channel', 'mail')->count());
    }

    public function test_a_recording_failure_never_resends_the_email(): void
    {
        $broken = new class extends Notification
        {
            /** @return array<string, mixed> */
            public function toArray(User $notifiable): array
            {
                throw new RuntimeException('cannot describe this email');
            }
        };

        app(RecordEmailDelivery::class)->handle(new NotificationSent($this->requester, $broken, 'mail'));

        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    private function submit(): Submission
    {
        $submission = Submission::factory()->create(['submitter_id' => $this->requester->id, 'status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);

        return $submission->fresh(['workflowInstance']);
    }
}
