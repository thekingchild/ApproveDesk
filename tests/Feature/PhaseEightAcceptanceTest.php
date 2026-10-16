<?php

namespace Tests\Feature;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Enums\ConnectorKey;
use App\Enums\IntegrationActionType;
use App\Enums\IntegrationDeliveryStatus;
use App\Enums\NotificationChannelKey;
use App\Enums\NotificationFrequency;
use App\Enums\RequestFieldType;
use App\Enums\SubmissionStatus;
use App\Models\IntegrationAction;
use App\Models\IntegrationConnector;
use App\Models\IntegrationDelivery;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Services\Notifications\ChannelSettings;
use App\Services\Notifications\ChatActionToken;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class PhaseEightAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_form_notifies_slack_accepts_a_signed_decision_and_uploads_to_drive(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
            'www.googleapis.com/*' => Http::response(['id' => 'drive-file-1'], 200),
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create([
            'notification_frequency' => NotificationFrequency::InAppOnly,
            'slack_user_id' => 'U123',
        ])->assignRole('approver');

        WorkflowTemplate::factory()->default()->sequential([$approver])->create();

        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Slack, true);
        $settings->putCredentials(NotificationChannelKey::Slack, [
            'bot_token' => 'xoxb-test',
            'signing_secret' => 'slack-signing',
        ]);

        $type = RequestType::factory()->create(['name' => 'Leave Request']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'reason',
            'label' => 'Reason',
            'type' => RequestFieldType::LongText,
            'is_required' => true,
        ]);
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'start_date',
            'label' => 'Start date',
            'type' => RequestFieldType::Date,
            'position' => 1,
            'is_required' => true,
        ]);
        app(PublishRequestTypeVersionAction::class)->execute($version);

        $drive = IntegrationConnector::factory()->create([
            'type' => ConnectorKey::GoogleDrive,
            'name' => 'Drive',
            'is_enabled' => true,
            'credentials' => ['access_token' => 'ya29.test', 'folder_id' => 'root'],
            'min_interval_seconds' => 0,
        ]);
        IntegrationAction::factory()->for($drive, 'connector')->create([
            'action' => IntegrationActionType::UploadFile,
            'name' => 'File to Drive',
            'is_enabled' => true,
        ]);

        Livewire::actingAs($submitter)
            ->test('pages::submissions.create-custom', ['requestType' => $type])
            ->set('values.reason', 'Family holiday')
            ->set('values.start_date', '2026-09-01')
            ->call('review')
            ->call('submit')
            ->assertHasNoErrors();

        $submission = Submission::query()->where('submitter_id', $submitter->id)->sole();
        $this->assertSame(SubmissionStatus::InReview, $submission->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
            && $request->hasHeader('Authorization', 'Bearer xoxb-test'));

        $instance = $submission->workflowInstance()->with('currentStep')->firstOrFail();
        $token = ChatActionToken::issue($instance, $instance->currentStep, $approver);
        $secret = 'slack-signing';
        $payload = json_encode(['type' => 'block_actions', 'user' => ['id' => $approver->slack_user_id], 'actions' => [['value' => $token]]]);
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
        $this->assertSame(IntegrationDeliveryStatus::Delivered, IntegrationDelivery::query()->sole()->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'googleapis.com/upload/drive'));
    }
}
