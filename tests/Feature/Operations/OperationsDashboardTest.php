<?php

namespace Tests\Feature\Operations;

use App\Actions\Operations\RecordSchedulerHeartbeatAction;
use App\Enums\IntegrationDeliveryStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Models\IntegrationConnector;
use App\Models\IntegrationDelivery;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_submitter_cannot_open_operations(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)
            ->get(route('admin.operations.index'))
            ->assertForbidden();
    }

    public function test_administrator_sees_health_cards_and_sidebar_entry(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        app(RecordSchedulerHeartbeatAction::class)->execute();

        $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Operations')
            ->assertSee('data-test="ops-heartbeat"', false)
            ->assertSee('data-test="contextual-help"', false)
            ->assertSee(route('help.show', 'operations'), false);
    }

    public function test_dashboard_counts_failed_deliveries_and_unhealthy_connectors(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $endpoint = WebhookEndpoint::factory()->create(['is_active' => true]);
        $endpoint->deliveries()->create([
            'event_id' => 'evt-1',
            'event' => 'workflow.completed',
            'payload' => ['ok' => false],
            'status' => WebhookDeliveryStatus::Failed,
            'last_error' => 'HTTP 503',
        ]);
        IntegrationConnector::factory()->create([
            'is_enabled' => true,
            'last_error' => 'Token expired',
        ]);
        IntegrationDelivery::factory()->create([
            'status' => IntegrationDeliveryStatus::DeadLetter,
            'last_error' => 'Token expired',
        ]);
        NotificationDelivery::query()->create([
            'user_id' => $admin->id,
            'kind' => 'action_required',
            'channel' => 'telegram',
            'status' => NotificationDeliveryStatus::Failed,
            'attempts' => 3,
            'last_error' => 'chat not found',
            'payload' => ['title' => 'Need a decision'],
        ]);

        Livewire::actingAs($admin)
            ->test('pages::admin.operations')
            ->assertSee('HTTP 503')
            ->assertSee('Token expired')
            ->assertSee('chat not found');
    }

    public function test_heartbeat_command_is_on_the_schedule_and_writes_a_snapshot(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command)
            ->filter();

        $this->assertTrue(
            $commands->contains(fn (string $command) => str_contains($command, 'ops:heartbeat')),
            'The scheduler must record a heartbeat each minute.',
        );

        $this->artisan('ops:heartbeat')->assertSuccessful();

        $this->assertNotNull(Setting::get('ops.scheduler_heartbeat_at'));
        $this->assertDatabaseCount('ops_snapshots', 1);
    }
}
