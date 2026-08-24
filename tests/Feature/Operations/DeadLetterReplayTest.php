<?php

namespace Tests\Feature\Operations;

use App\Actions\Operations\ReplayDeadLetterAction;
use App\Enums\IntegrationDeliveryStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverIntegrationAction;
use App\Jobs\DeliverWebhook;
use App\Jobs\RetryNotificationDeliveries;
use App\Models\IntegrationDelivery;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class DeadLetterReplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['queue.default' => 'database']);
    }

    public function test_failed_webhook_can_be_replayed_and_delivered_cannot(): void
    {
        Queue::fake();
        $endpoint = WebhookEndpoint::factory()->create();
        $failed = $endpoint->deliveries()->create([
            'event_id' => (string) Str::uuid(),
            'event' => 'workflow.completed',
            'payload' => ['id' => 1],
            'status' => WebhookDeliveryStatus::Failed,
            'attempts' => 5,
            'last_error' => 'timeout',
        ]);
        $delivered = $endpoint->deliveries()->create([
            'event_id' => (string) Str::uuid(),
            'event' => 'workflow.completed',
            'payload' => ['id' => 2],
            'status' => WebhookDeliveryStatus::Delivered,
        ]);

        app(ReplayDeadLetterAction::class)->execute('webhook', (string) $failed->id);

        $this->assertSame(WebhookDeliveryStatus::Pending, $failed->fresh()->status);
        $this->assertSame(0, $failed->fresh()->attempts);
        Queue::assertPushed(DeliverWebhook::class);

        $this->expectException(InvalidArgumentException::class);
        app(ReplayDeadLetterAction::class)->execute('webhook', (string) $delivered->id);
    }

    public function test_dead_letter_integration_replays_and_delivered_does_not(): void
    {
        Queue::fake();
        $dead = IntegrationDelivery::factory()->create([
            'status' => IntegrationDeliveryStatus::DeadLetter,
            'attempts' => 5,
        ]);
        $delivered = IntegrationDelivery::factory()->create([
            'status' => IntegrationDeliveryStatus::Delivered,
        ]);

        app(ReplayDeadLetterAction::class)->execute('integration', (string) $dead->id);
        $this->assertSame(IntegrationDeliveryStatus::Pending, $dead->fresh()->status);
        Queue::assertPushed(DeliverIntegrationAction::class);

        $this->expectException(InvalidArgumentException::class);
        app(ReplayDeadLetterAction::class)->execute('integration', (string) $delivered->id);
    }

    public function test_failed_notification_replay_resets_attempts_and_queues_retry(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $delivery = NotificationDelivery::query()->create([
            'user_id' => $user->id,
            'kind' => 'action_required',
            'channel' => 'telegram',
            'status' => NotificationDeliveryStatus::Failed,
            'attempts' => 3,
            'last_error' => 'down',
            'payload' => ['title' => 'Retry'],
        ]);

        app(ReplayDeadLetterAction::class)->execute('notification', (string) $delivery->id);

        $this->assertSame(0, $delivery->fresh()->attempts);
        Queue::assertPushed(RetryNotificationDeliveries::class);
    }

    public function test_failed_job_is_moved_back_onto_the_queue(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\DeliverWebhook', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: boom',
            'failed_at' => now(),
        ]);

        $this->artisan('ops:replay', ['type' => 'job', 'id' => $uuid])->assertSuccessful();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);
    }

    public function test_operations_screen_replay_is_forbidden_for_submitters(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $delivery = IntegrationDelivery::factory()->create([
            'status' => IntegrationDeliveryStatus::DeadLetter,
        ]);

        $this->actingAs($submitter)
            ->get(route('admin.operations.index'))
            ->assertForbidden();
    }
}
