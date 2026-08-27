<?php

namespace Tests\Feature\Webhooks;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Events\WorkflowCompleted;
use App\Jobs\DeliverWebhook;
use App\Models\Submission;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WorkflowTemplate;
use App\Support\DnsResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\ArrayDnsResolver;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
    }

    public function test_subscribed_workflow_event_creates_a_delivery_and_queues_it(): void
    {
        $endpoint = WebhookEndpoint::factory()->create([
            'events' => [WebhookEvent::WorkflowCompleted->value],
        ]);
        $submission = $this->submittedSubmission();
        $instance = $submission->workflowInstance()->firstOrFail();

        Queue::fake();

        event(new WorkflowCompleted($instance));

        $this->assertDatabaseHas('webhook_deliveries', [
            'endpoint_id' => $endpoint->id,
            'event' => WebhookEvent::WorkflowCompleted->value,
            'status' => WebhookDeliveryStatus::Pending->value,
        ]);
        Queue::assertPushed(DeliverWebhook::class, 1);
    }

    public function test_delivery_uses_timestamped_hmac_signature_and_records_success(): void
    {
        $endpoint = WebhookEndpoint::factory()->create([
            'url' => 'https://hooks.example.test/approvedesk',
            'secret' => 'webhook-secret',
        ]);
        $delivery = $endpoint->deliveries()->create([
            'event_id' => (string) Str::uuid(),
            'event' => WebhookEvent::WorkflowCompleted->value,
            'payload' => ['event' => WebhookEvent::WorkflowCompleted->value, 'data' => ['submission' => ['id' => 42]]],
            'status' => WebhookDeliveryStatus::Pending,
        ]);

        Http::fake(['https://hooks.example.test/*' => Http::response('Accepted', 202)]);

        (new DeliverWebhook($delivery->id))->handle();

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(202, $delivery->response_status);
        $this->assertNotNull($delivery->delivered_at);

        Http::assertSent(function (Request $request) use ($delivery): bool {
            $timestamp = $request->header('X-ApproveDesk-Timestamp')[0] ?? '';
            $signature = $request->header('X-ApproveDesk-Signature')[0] ?? '';

            return $request->url() === 'https://hooks.example.test/approvedesk'
                && $request->header('X-ApproveDesk-Event')[0] === WebhookEvent::WorkflowCompleted->value
                && $request->header('X-ApproveDesk-Delivery')[0] === $delivery->event_id
                && hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'webhook-secret'), $signature);
        });
    }

    public function test_retryable_failure_is_audited_before_the_job_is_released(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.test/approvedesk']);
        $delivery = $endpoint->deliveries()->create([
            'event_id' => (string) Str::uuid(),
            'event' => WebhookEvent::SubmissionCreated->value,
            'payload' => ['event' => WebhookEvent::SubmissionCreated->value],
            'status' => WebhookDeliveryStatus::Pending,
        ]);

        Http::fake(['https://hooks.example.test/*' => Http::response('Unavailable', 503)]);

        try {
            (new DeliverWebhook($delivery->id))->handle();
            $this->fail('The failed delivery should be retried by the queue.');
        } catch (RuntimeException) {
            // Expected: the queue retries the job using its backoff schedule.
        }

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Pending, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(503, $delivery->response_status);
        $this->assertNotNull($delivery->next_attempt_at);
    }

    public function test_paused_endpoint_marks_queued_delivery_as_not_sent(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['is_active' => false]);
        $delivery = $endpoint->deliveries()->create([
            'event_id' => (string) Str::uuid(),
            'event' => WebhookEvent::SubmissionCreated->value,
            'payload' => ['event' => WebhookEvent::SubmissionCreated->value],
            'status' => WebhookDeliveryStatus::Pending,
        ]);

        Http::fake();

        (new DeliverWebhook($delivery->id))->handle();

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->status);
        $this->assertSame('Endpoint was inactive when delivery was attempted.', $delivery->last_error);
        Http::assertNothingSent();
    }

    public function test_rebinding_hostname_is_refused_at_delivery(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://rebind.example.test/hooks']);
        $delivery = $endpoint->deliveries()->create([
            'event_id' => (string) Str::uuid(),
            'event' => WebhookEvent::WorkflowCompleted->value,
            'payload' => ['event' => WebhookEvent::WorkflowCompleted->value],
            'status' => WebhookDeliveryStatus::Pending,
        ]);

        $resolver = app(DnsResolver::class);
        $this->assertInstanceOf(ArrayDnsResolver::class, $resolver);
        $resolver->set('rebind.example.test', ['10.0.0.1']);

        Http::fake();

        try {
            (new DeliverWebhook($delivery->id))->handle();
            $this->fail('Expected delivery to refuse a rebinding hostname.');
        } catch (RuntimeException) {
            // expected
        }

        $delivery->refresh();
        $this->assertSame('The destination is not allowed.', $delivery->last_error);
        Http::assertNothingSent();
    }

    private function submittedSubmission(): Submission
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);

        app(SubmitSubmissionAction::class)->execute($submission);

        return $submission;
    }
}
