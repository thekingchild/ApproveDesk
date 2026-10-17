<?php

namespace Tests\Feature\Queue;

use App\Enums\IntegrationDeliveryStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverIntegrationAction;
use App\Jobs\DeliverWebhook;
use App\Models\IntegrationAction;
use App\Models\IntegrationConnector;
use App\Models\IntegrationDelivery;
use App\Models\Submission;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Integrations\ConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** RV-LAR-07: a delivery job the queue gives up on must not leave its row Pending. */
class DeliveryJobFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_rv_lar_07_integration_delivery_is_dead_lettered_when_the_job_dies(): void
    {
        $delivery = $this->integrationDelivery(['credentials' => ['url' => 'https://hooks.example.test/x', 'secret' => 'top-secret-value']]);

        (new DeliverIntegrationAction($delivery->id))->failed(new MaxAttemptsExceededException('timed out near top-secret-value'));

        $delivery->refresh();
        $this->assertSame(IntegrationDeliveryStatus::DeadLetter, $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertStringContainsString('The delivery job stopped', (string) $delivery->last_error);
        $this->assertStringNotContainsString('top-secret-value', (string) $delivery->last_error);
    }

    public function test_rv_lar_07_failed_handler_leaves_a_finished_integration_delivery_alone(): void
    {
        $delivery = $this->integrationDelivery([], ['status' => IntegrationDeliveryStatus::Delivered, 'last_error' => null]);

        (new DeliverIntegrationAction($delivery->id))->failed(new MaxAttemptsExceededException('late'));

        $this->assertSame(IntegrationDeliveryStatus::Delivered, $delivery->fresh()->status);
    }

    public function test_rv_lar_07_rate_limited_delivery_waits_without_using_an_attempt(): void
    {
        Http::fake();
        $delivery = $this->integrationDelivery(['min_interval_seconds' => 60, 'last_request_at' => now()]);

        (new DeliverIntegrationAction($delivery->id))->handle(app(ConnectorRegistry::class));

        $delivery->refresh();
        $this->assertSame(IntegrationDeliveryStatus::Pending, $delivery->status);
        $this->assertSame(0, $delivery->attempts);
        $this->assertNotNull($delivery->next_attempt_at);
        Http::assertNothingSent();
    }

    public function test_rv_lar_07_webhook_delivery_is_marked_failed_when_the_job_dies(): void
    {
        $delivery = $this->webhookDelivery(WebhookDeliveryStatus::Pending);

        (new DeliverWebhook($delivery->id))->failed(new MaxAttemptsExceededException('timed out'));

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertStringContainsString('The delivery job stopped', (string) $delivery->last_error);
    }

    public function test_rv_lar_07_failed_handler_leaves_a_delivered_webhook_alone(): void
    {
        $delivery = $this->webhookDelivery(WebhookDeliveryStatus::Delivered);

        (new DeliverWebhook($delivery->id))->failed(new MaxAttemptsExceededException('late'));

        $this->assertSame(WebhookDeliveryStatus::Delivered, $delivery->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $connector
     * @param  array<string, mixed>  $delivery
     */
    private function integrationDelivery(array $connector = [], array $delivery = []): IntegrationDelivery
    {
        $action = IntegrationAction::factory()
            ->for(IntegrationConnector::factory()->create($connector), 'connector')
            ->create();

        return IntegrationDelivery::factory()->create(array_merge([
            'integration_action_id' => $action->id,
            'submission_id' => Submission::factory()->create()->id,
            'status' => IntegrationDeliveryStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => now()->addMinute(),
        ], $delivery));
    }

    private function webhookDelivery(WebhookDeliveryStatus $status): WebhookDelivery
    {
        return WebhookEndpoint::factory()->create()->deliveries()->create([
            'event_id' => (string) Str::uuid(),
            'event' => WebhookEvent::WorkflowCompleted->value,
            'payload' => ['event' => WebhookEvent::WorkflowCompleted->value],
            'status' => $status,
            'next_attempt_at' => now()->addMinute(),
        ]);
    }
}
