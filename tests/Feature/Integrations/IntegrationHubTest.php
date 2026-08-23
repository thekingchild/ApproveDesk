<?php

namespace Tests\Feature\Integrations;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\ConnectorKey;
use App\Enums\IntegrationActionType;
use App\Enums\IntegrationDeliveryStatus;
use App\Events\WorkflowCompleted;
use App\Jobs\DeliverIntegrationAction;
use App\Models\IntegrationAction;
use App\Models\IntegrationConnector;
use App\Models\IntegrationDelivery;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Integrations\ConnectorRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrationHubTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $submitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
    }

    public function test_registry_lists_every_planned_connector(): void
    {
        $keys = collect(app(ConnectorRegistry::class)->all())->map(fn ($c) => $c->key())->all();

        $this->assertEqualsCanonicalizing(ConnectorKey::cases(), $keys);
    }

    public function test_http_connector_health_rejects_non_https_urls(): void
    {
        $connector = app(ConnectorRegistry::class)->get(ConnectorKey::Http);
        $health = $connector->healthCheck(['url' => 'http://insecure.example']);

        $this->assertFalse($health->ok);
    }

    public function test_completed_workflow_queues_matching_action_once(): void
    {
        $connector = IntegrationConnector::factory()->create(['is_enabled' => true]);
        $action = IntegrationAction::factory()->for($connector, 'connector')->create([
            'action' => IntegrationActionType::InvokeWebhook,
            'is_enabled' => true,
        ]);
        $submission = $this->submittedSubmission();

        Queue::fake();
        event(new WorkflowCompleted($submission->workflowInstance()->firstOrFail()));
        event(new WorkflowCompleted($submission->workflowInstance()->firstOrFail()));

        $this->assertSame(1, IntegrationDelivery::query()->where('integration_action_id', $action->id)->count());
        Queue::assertPushed(DeliverIntegrationAction::class);
    }

    public function test_delivery_posts_signed_payload_and_redacts_secrets_on_failure(): void
    {
        $connector = IntegrationConnector::factory()->create([
            'credentials' => ['url' => 'https://hooks.example.test/in', 'secret' => 'super-secret-token'],
        ]);
        $action = IntegrationAction::factory()->for($connector, 'connector')->create();
        $submission = $this->submittedSubmission();
        $delivery = IntegrationDelivery::factory()->create([
            'integration_action_id' => $action->id,
            'submission_id' => $submission->id,
            'status' => IntegrationDeliveryStatus::Pending,
        ]);

        Http::fake(['https://hooks.example.test/*' => Http::response('super-secret-token leaked', 500)]);

        try {
            (new DeliverIntegrationAction($delivery->id))->handle(app(ConnectorRegistry::class));
            $this->fail('Expected delivery to throw');
        } catch (\RuntimeException) {
        }

        $delivery->refresh();
        $this->assertSame(IntegrationDeliveryStatus::Pending, $delivery->status);
        $this->assertStringNotContainsString('super-secret-token', (string) $delivery->last_error);
        $this->assertStringContainsString('[redacted]', (string) $delivery->last_error);
    }

    public function test_successful_delivery_is_marked_delivered(): void
    {
        $connector = IntegrationConnector::factory()->create();
        $action = IntegrationAction::factory()->for($connector, 'connector')->create();
        $submission = $this->submittedSubmission();
        $delivery = IntegrationDelivery::factory()->create([
            'integration_action_id' => $action->id,
            'submission_id' => $submission->id,
        ]);

        Http::fake(['https://hooks.example.test/*' => Http::response('ok', 200)]);

        (new DeliverIntegrationAction($delivery->id))->handle(app(ConnectorRegistry::class));

        $this->assertSame(IntegrationDeliveryStatus::Delivered, $delivery->fresh()->status);
    }

    public function test_already_delivered_action_is_not_queued_again(): void
    {
        $connector = IntegrationConnector::factory()->create(['is_enabled' => true]);
        $action = IntegrationAction::factory()->for($connector, 'connector')->create(['is_enabled' => true]);
        $submission = $this->submittedSubmission();
        IntegrationDelivery::factory()->create([
            'integration_action_id' => $action->id,
            'submission_id' => $submission->id,
            'idempotency_key' => 'completed:'.$submission->id.':'.$action->id,
            'status' => IntegrationDeliveryStatus::Delivered,
        ]);

        Queue::fake();
        event(new WorkflowCompleted($submission->workflowInstance()->firstOrFail()));

        $this->assertSame(1, IntegrationDelivery::query()->where('integration_action_id', $action->id)->count());
        Queue::assertNotPushed(DeliverIntegrationAction::class);
    }

    public function test_replay_requeues_a_dead_letter(): void
    {
        Queue::fake();
        $delivery = IntegrationDelivery::factory()->create([
            'status' => IntegrationDeliveryStatus::DeadLetter,
            'attempts' => 5,
        ]);

        Livewire::actingAs($this->admin)
            ->test('pages::admin.integrations')
            ->call('replay', $delivery->id)
            ->assertHasNoErrors();

        $this->assertSame(1, IntegrationDelivery::query()->count());
        $this->assertSame(IntegrationDeliveryStatus::Pending, $delivery->fresh()->status);
        $this->assertSame(0, $delivery->fresh()->attempts);
        Queue::assertPushed(DeliverIntegrationAction::class);
    }

    public function test_admin_can_create_connector_and_save_encrypted_credentials(): void
    {
        Livewire::actingAs($this->admin)
            ->test('pages::admin.integrations')
            ->set('connectorName', 'ERP hook')
            ->set('connectorType', ConnectorKey::Http->value)
            ->call('addConnector')
            ->assertHasNoErrors();

        $connector = IntegrationConnector::query()->where('name', 'ERP hook')->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.integrations.credentials', $connector), [
            'url' => 'https://erp.example.test/hook',
            'secret' => 'keep-me',
        ])->assertRedirect(route('admin.integrations.index'));

        $this->assertSame('keep-me', $connector->fresh()->credentialMap()['secret']);
    }

    public function test_submitter_cannot_open_integration_hub(): void
    {
        $this->actingAs($this->submitter)->get(route('admin.integrations.index'))->assertForbidden();
    }

    private function submittedSubmission(): Submission
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create();
        app(SubmitSubmissionAction::class)->execute($submission);

        return $submission->fresh();
    }
}
