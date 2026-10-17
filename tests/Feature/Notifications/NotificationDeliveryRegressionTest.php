<?php

namespace Tests\Feature\Notifications;

use App\Actions\Collaboration\FollowSubmissionAction;
use App\Actions\Collaboration\PostCommentAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\DTOs\WorkflowActionData;
use App\Enums\AssigneeType;
use App\Enums\NotificationChannelKey;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationFrequency;
use App\Enums\StepType;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Events\SubmissionCreated;
use App\Jobs\RetryNotificationDeliveries;
use App\Jobs\SendNotificationDigests;
use App\Listeners\NotifyCurrentApprovers;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\CommentPostedNotification;
use App\Notifications\NotificationDigest;
use App\Notifications\SubmissionDecidedNotification;
use App\Notifications\WatcherStatusNotification;
use App\Services\Notifications\ChannelSettings;
use App\Services\Notifications\DigestSchedule;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests for notification delivery review findings:
 * RV-BUG-15 (chat failures/skips recorded truthfully), RV-BUG-16 (deferred
 * chat keeps its copy and link, and is dropped once stale), RV-BUG-17
 * (decision email comment), RV-BUG-18 (daily digest window), RV-BUG-20
 * (inactive people get nothing), RV-PERF-15 (submission loaded once) and
 * RV-PERF-18 (bounded retry batch).
 */
class NotificationDeliveryRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enableSlack(): void
    {
        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Slack, true);
        $settings->putCredentials(NotificationChannelKey::Slack, ['bot_token' => 'xoxb-test']);
    }

    private function enableTelegram(): void
    {
        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Telegram, true);
        $settings->putCredentials(NotificationChannelKey::Telegram, ['bot_token' => '123:abc', 'webhook_secret' => 's']);
    }

    private function runRetries(): void
    {
        app()->call([new RetryNotificationDeliveries, 'handle']);
    }

    public function test_slack_refusal_is_recorded_as_failed_and_scheduled_for_retry(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => false, 'error' => 'channel_not_found'], 200)]);
        $this->enableSlack();

        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::InAppOnly, 'slack_user_id' => 'U123']);
        $user->notify(new ActionRequiredNotification(Submission::factory()->create()));

        $delivery = NotificationDelivery::query()->where('channel', 'slack')->firstOrFail();
        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertStringContainsString('channel_not_found', (string) $delivery->last_error);
        $this->assertNotNull($delivery->next_retry_at);
        $this->assertNull($delivery->sent_at);
    }

    public function test_slack_acceptance_is_recorded_as_sent(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true], 200)]);
        $this->enableSlack();

        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::InAppOnly, 'slack_user_id' => 'U123']);
        $user->notify(new ActionRequiredNotification(Submission::factory()->create()));

        $this->assertSame(
            NotificationDeliveryStatus::Sent,
            NotificationDelivery::query()->where('channel', 'slack')->firstOrFail()->status,
        );
    }

    public function test_telegram_error_on_a_non_critical_kind_is_failed_without_retry(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        $this->enableTelegram();

        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::InAppOnly, 'telegram_user_id' => '777']);
        $user->notify(new SubmissionDecidedNotification(Submission::factory()->create(), SubmissionStatus::Approved));

        $delivery = NotificationDelivery::query()->where('channel', 'telegram')->firstOrFail();
        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertNull($delivery->next_retry_at);
        $this->assertStringNotContainsString('123:abc', (string) $delivery->last_error);

        // The retry job honours the recorder's no-retry decision.
        Carbon::setTestNow(now()->addHour());
        $this->runRetries();
        $this->assertSame(1, $delivery->fresh()->attempts);
        Http::assertSentCount(1);
    }

    public function test_unmapped_whatsapp_kind_is_recorded_as_skipped(): void
    {
        Http::fake();
        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::WhatsApp, true);
        $settings->putCredentials(NotificationChannelKey::WhatsApp, [
            'waba_id' => 'waba', 'phone_number_id' => 'pn', 'access_token' => 'token',
        ]);

        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::InAppOnly, 'phone' => '+15551234567']);
        $user->notify(new ActionRequiredNotification(Submission::factory()->create()));

        Http::assertNothingSent();
        $delivery = NotificationDelivery::query()->where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame(NotificationDeliveryStatus::Skipped, $delivery->status);
        $this->assertNull($delivery->next_retry_at);
    }

    public function test_deferred_action_required_chat_keeps_its_copy_and_signed_link(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->enableTelegram();
        Setting::put('nudge.quiet_hours', json_encode(['start' => '22:00', 'end' => '07:00']));

        $approver = User::factory()->create(['notification_frequency' => NotificationFrequency::InAppOnly, 'telegram_user_id' => '777']);
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create(['title' => 'Capex']);

        Carbon::setTestNow('2026-10-16 23:00:00');
        app(WorkflowEngine::class)->initialize($submission);
        $approver->notify(new ActionRequiredNotification($submission->fresh()));
        Http::assertNothingSent();
        $this->assertSame(NotificationDeliveryStatus::Deferred, NotificationDelivery::query()->firstOrFail()->status);

        Carbon::setTestNow('2026-10-17 07:15:00');
        $this->runRetries();

        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Action required: Capex')
            && str_contains((string) $request['text'], 'signature='));
        $this->assertSame(NotificationDeliveryStatus::Sent, NotificationDelivery::query()->firstOrFail()->status);
    }

    public function test_deferred_action_required_chat_is_skipped_once_the_step_moved_on(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->enableTelegram();
        Setting::put('nudge.quiet_hours', json_encode(['start' => '22:00', 'end' => '07:00']));

        $approver = User::factory()->create(['notification_frequency' => NotificationFrequency::InAppOnly, 'telegram_user_id' => '777']);
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create(['title' => 'Capex']);

        Carbon::setTestNow('2026-10-16 23:00:00');
        $instance = app(WorkflowEngine::class)->initialize($submission);
        $approver->notify(new ActionRequiredNotification($submission->fresh()));

        Carbon::setTestNow('2026-10-16 23:30:00');
        app(WorkflowEngine::class)->processAction($instance->fresh(), new WorkflowActionData($approver, WorkflowActionType::Approve));

        Carbon::setTestNow('2026-10-17 07:15:00');
        $this->runRetries();

        Http::assertNothingSent();
        $delivery = NotificationDelivery::query()->where('kind', 'action_required')->firstOrFail();
        $this->assertSame(NotificationDeliveryStatus::Skipped, $delivery->status);
    }

    public function test_retry_job_takes_a_bounded_batch_per_run(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->enableTelegram();

        $user = User::factory()->create(['telegram_user_id' => '777']);
        foreach (range(1, RetryNotificationDeliveries::BATCH_SIZE + 5) as $i) {
            NotificationDelivery::query()->create([
                'user_id' => $user->id,
                'kind' => 'comment',
                'channel' => 'telegram',
                'status' => NotificationDeliveryStatus::Deferred,
                'attempts' => 0,
                'payload' => ['title' => 'Update '.$i, 'kind' => 'comment'],
                'next_retry_at' => now()->subMinute(),
            ]);
        }

        $this->runRetries();

        Http::assertSentCount(RetryNotificationDeliveries::BATCH_SIZE);
        $this->assertSame(5, NotificationDelivery::query()->where('status', NotificationDeliveryStatus::Deferred->value)->count());
    }

    public function test_approved_email_has_no_placeholder_and_no_stale_return_comment(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        $engine = app(WorkflowEngine::class);
        Notification::fake();
        $instance = $engine->initialize($submission);

        $mail = (new SubmissionDecidedNotification($submission, SubmissionStatus::Approved))->toMail($submitter);
        $this->assertStringNotContainsString('{{', implode("\n", $mail->introLines));

        $engine->processAction($instance->fresh(), new WorkflowActionData($approver, WorkflowActionType::Return, 'Please fix section 2.'));
        $returned = (new SubmissionDecidedNotification($submission->fresh(), SubmissionStatus::Returned))->toMail($submitter);
        $this->assertContains('Please fix section 2.', $returned->introLines);

        $engine->resume($instance->fresh());
        $engine->processAction($instance->fresh(), new WorkflowActionData($approver, WorkflowActionType::Approve, 'Thanks, looks right now.'));
        $approved = (new SubmissionDecidedNotification($submission->fresh(), SubmissionStatus::Approved))->toMail($submitter);
        $body = implode("\n", $approved->introLines);
        $this->assertStringNotContainsString('Please fix section 2.', $body);
        $this->assertStringNotContainsString('{{', $body);
        $this->assertContains('Thanks, looks right now.', $approved->introLines);
    }

    public function test_daily_digest_is_sent_when_the_job_starts_seconds_earlier_than_yesterday(): void
    {
        Notification::fake();
        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::Daily]);

        Carbon::setTestNow('2026-10-14 08:01:05');
        $this->inboxItem($user, 'A');
        (new SendNotificationDigests)->handle(app(DigestSchedule::class));
        Notification::assertSentToTimes($user, NotificationDigest::class, 1);

        // A second run the same day sends nothing new.
        Carbon::setTestNow('2026-10-14 20:00:00');
        $this->inboxItem($user, 'A2');
        (new SendNotificationDigests)->handle(app(DigestSchedule::class));
        Notification::assertSentToTimes($user, NotificationDigest::class, 1);

        Carbon::setTestNow('2026-10-15 08:01:02');
        $this->inboxItem($user, 'B');
        (new SendNotificationDigests)->handle(app(DigestSchedule::class));
        Notification::assertSentToTimes($user, NotificationDigest::class, 2);
    }

    public function test_deactivated_people_get_no_decision_follower_comment_or_digest_notifications(): void
    {
        Notification::fake();
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create(['status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);
        $submission = $submission->fresh();

        $follower = User::factory()->create()->assignRole('approver');
        app(FollowSubmissionAction::class)->execute($submission, $submitter, $follower);

        $submitter->forceFill(['is_active' => false])->save();
        $follower->forceFill(['is_active' => false])->save();

        app(PostCommentAction::class)->execute($submission, $approver, 'Checking the numbers.');
        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->firstOrFail(), new WorkflowActionData($approver, WorkflowActionType::Approve));

        Notification::assertNotSentTo($submitter, SubmissionDecidedNotification::class);
        Notification::assertNotSentTo($follower, WatcherStatusNotification::class);
        Notification::assertNotSentTo($follower, CommentPostedNotification::class);

        $follower->forceFill(['notification_frequency' => NotificationFrequency::Daily])->save();
        $this->inboxItem($follower, 'Old item');
        (new SendNotificationDigests)->handle(app(DigestSchedule::class));
        Notification::assertNotSentTo($follower, NotificationDigest::class);
    }

    public function test_active_people_still_get_decision_and_follower_notifications(): void
    {
        Notification::fake();
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create(['status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);
        $submission = $submission->fresh();
        $follower = User::factory()->create()->assignRole('approver');
        app(FollowSubmissionAction::class)->execute($submission, $submitter, $follower);

        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->firstOrFail(), new WorkflowActionData($approver, WorkflowActionType::Approve));

        Notification::assertSentTo($submitter, SubmissionDecidedNotification::class, fn ($n, array $channels): bool => in_array('mail', $channels, true));
        Notification::assertSentTo($follower, WatcherStatusNotification::class);
    }

    public function test_current_approvers_listener_loads_the_submission_once(): void
    {
        Notification::fake();
        $approvers = User::factory()->count(5)->create()->each->assignRole('approver');
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Parallel',
            'step_type' => StepType::Parallel,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => $approvers->pluck('id')->all(),
        ]);
        $submission = Submission::factory()->create();
        $instance = app(WorkflowEngine::class)->initialize($submission);

        $submissionQueries = 0;
        DB::listen(function ($query) use (&$submissionQueries): void {
            if (preg_match('/from\s+["`]?submissions["`]?\s/i', $query->sql) === 1) {
                $submissionQueries++;
            }
        });

        (new NotifyCurrentApprovers)->handle(new SubmissionCreated($submission, $instance->fresh()));

        $this->assertLessThanOrEqual(1, $submissionQueries);
        foreach ($approvers as $approver) {
            Notification::assertSentTo($approver, ActionRequiredNotification::class);
        }
    }

    private function inboxItem(User $user, string $title): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ActionRequiredNotification::class,
            'data' => ['title' => $title, 'kind' => 'action_required'],
            'created_at' => now()->subMinute(),
        ]);
    }
}
