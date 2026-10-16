<?php

namespace Tests\Feature\Notifications;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\NotificationChannelKey;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Services\Notifications\ChannelSettings;
use App\Services\Notifications\ChatActionToken;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChatActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_signed_slack_approve_records_an_approval_and_tampered_tokens_are_rejected(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create(['slack_user_id' => 'U0APPROVER'])->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create(['status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);
        $instance = $submission->workflowInstance()->with('currentStep')->firstOrFail();
        $token = ChatActionToken::issue($instance, $instance->currentStep, $approver);

        $secret = 'slack-signing';
        app(ChannelSettings::class)->putCredentials(
            NotificationChannelKey::Slack,
            ['bot_token' => 'xoxb-x', 'signing_secret' => $secret],
        );

        $payload = json_encode(['type' => 'block_actions', 'user' => ['id' => 'U0APPROVER'], 'actions' => [['value' => $token]]]);
        $timestamp = (string) time();
        $body = http_build_query(['payload' => $payload]);
        $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, $secret);

        $this->call('POST', route('integrations.slack.interactions'), ['payload' => $payload], [], [], [
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_SLACK_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body)->assertOk();

        $this->assertSame(SubmissionStatus::Approved, $submission->fresh()->status);
        $this->assertSame(1, WorkflowAction::query()->count());

        $this->call('POST', route('integrations.slack.interactions'), ['payload' => $payload], [], [], [
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_SLACK_SIGNATURE' => 'v0=deadbeef',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body)->assertForbidden();
    }

    public function test_stale_chat_token_does_not_mutate_workflow(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create(['status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);
        $instance = $submission->workflowInstance()->with('currentStep')->firstOrFail();
        $token = ChatActionToken::issue($instance, $instance->currentStep, $approver);
        app(WorkflowEngine::class)->processAction($instance, $approver, WorkflowActionType::Approve);

        app(ChannelSettings::class)->setEnabled(NotificationChannelKey::Teams, true);
        app(ChannelSettings::class)->putCredentials(
            NotificationChannelKey::Teams,
            ['app_id' => 'id', 'app_password' => 'secret'],
        );

        $this->postJson(route('integrations.teams.actions'), ['token' => $token])
            ->assertOk()
            ->assertJsonFragment(['status' => 'stale']);
        $this->assertSame(1, WorkflowAction::query()->count());
    }

    public function test_tampered_chat_token_is_rejected_and_does_not_mutate_workflow(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create(['status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);
        $instance = $submission->workflowInstance()->with('currentStep')->firstOrFail();
        $token = ChatActionToken::issue($instance, $instance->currentStep, $approver);
        $tampered = substr($token, 0, -6).'xxxxxx';

        app(ChannelSettings::class)->setEnabled(NotificationChannelKey::Teams, true);
        app(ChannelSettings::class)->putCredentials(
            NotificationChannelKey::Teams,
            ['app_id' => 'id', 'app_password' => 'secret'],
        );

        $this->postJson(route('integrations.teams.actions'), ['token' => $tampered])
            ->assertOk()
            ->assertJsonFragment(['status' => 'stale']);
        $this->assertSame(0, WorkflowAction::query()->count());
        $this->assertSame(SubmissionStatus::InReview, $submission->fresh()->status);
    }

    public function test_expired_chat_token_is_rejected(): void
    {
        Carbon::setTestNow('2026-08-21 12:00:00');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();
        app(WorkflowEngine::class)->initialize($submission);
        $instance = $submission->workflowInstance()->with('currentStep')->firstOrFail();
        $token = ChatActionToken::issue($instance, $instance->currentStep, $approver);
        Carbon::setTestNow('2026-09-01 12:00:00');
        $this->expectException(\InvalidArgumentException::class);
        ChatActionToken::parse($token);
    }
}
