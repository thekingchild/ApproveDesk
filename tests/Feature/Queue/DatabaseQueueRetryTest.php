<?php

namespace Tests\Feature\Queue;

use App\Enums\IntegrationDeliveryStatus;
use App\Enums\NotificationChannelKey;
use App\Enums\NotificationDeliveryStatus;
use App\Jobs\DeliverIntegrationAction;
use App\Jobs\RetryNotificationDeliveries;
use App\Models\IntegrationAction;
use App\Models\IntegrationConnector;
use App\Models\IntegrationDelivery;
use App\Models\NotificationDelivery;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\Notifications\ChannelSettings;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class DatabaseQueueRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['queue.default' => 'database']);
    }

    public function test_integration_delivery_retries_then_dead_letters_on_the_database_queue(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $connector = IntegrationConnector::factory()->create([
            'min_interval_seconds' => 0,
        ]);
        $action = IntegrationAction::factory()->for($connector, 'connector')->create();
        $delivery = IntegrationDelivery::factory()->create([
            'integration_action_id' => $action->id,
            'submission_id' => Submission::factory()->create()->id,
            'status' => IntegrationDeliveryStatus::Pending,
            'attempts' => 0,
        ]);

        DeliverIntegrationAction::dispatch($delivery->id);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);

        $job = new DeliverIntegrationAction($delivery->id);
        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600], $job->backoff);

        Carbon::setTestNow('2026-08-23 12:00:00');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $job->handle(app(ConnectorRegistry::class));
                $this->fail('A 503 delivery should throw so the database queue can retry.');
            } catch (RuntimeException) {
            }

            $delivery->refresh();
            Carbon::setTestNow(now()->addSeconds(2));
        }

        $this->assertSame(IntegrationDeliveryStatus::DeadLetter, $delivery->status);
        $this->assertSame(5, $delivery->attempts);
        $this->assertNull($delivery->next_attempt_at);
    }

    public function test_failed_action_required_chat_is_retried_by_the_database_queue_job(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]]),
        ]);

        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Telegram, true);
        $settings->putCredentials(NotificationChannelKey::Telegram, [
            'bot_token' => '123:abc',
            'webhook_secret' => 'hook-secret',
        ]);

        $user = User::factory()->create(['telegram_user_id' => '42']);
        WorkflowTemplate::factory()->default()->sequential([$user])->create();
        $submission = Submission::factory()->create(['title' => 'Retry me']);
        Notification::fake();
        app(WorkflowEngine::class)->initialize($submission);
        $delivery = NotificationDelivery::query()->create([
            'user_id' => $user->id,
            'kind' => 'action_required',
            'channel' => 'telegram',
            'status' => NotificationDeliveryStatus::Failed,
            'attempts' => 1,
            'payload' => [
                'title' => 'Retry me',
                'submission_id' => $submission->id,
            ],
            'next_retry_at' => now()->subMinute(),
        ]);

        RetryNotificationDeliveries::dispatch();
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);

        app()->call([new RetryNotificationDeliveries, 'handle']);

        $this->assertSame(NotificationDeliveryStatus::Sent, $delivery->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === '42');
    }
}
