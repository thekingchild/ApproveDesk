<?php

namespace Tests\Feature\Workflow;

use App\Actions\Collaboration\DeleteOutOfOfficeAction;
use App\Actions\Collaboration\SaveOutOfOfficeAction;
use App\DTOs\DelegationData;
use App\DTOs\WorkflowActionData;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Delegation;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Services\Audit\EvidencePackBuilder;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-ENG-12 (delegations can be ended; inactive delegates don't hold steps) and
 * RV-DATA-05 (expired delegations stay as evidence).
 */
class DelegationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private User $delegate;

    private WorkflowInstance $instance;

    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->engine = app(WorkflowEngine::class);
        $this->approver = User::factory()->create()->assignRole('approver');
        $this->delegate = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        $this->instance = $this->engine->initialize(Submission::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_deactivating_the_delegate_gives_the_step_back(): void
    {
        $this->engine->delegate($this->instance, new DelegationData($this->approver, $this->delegate, now()->addDays(14)));
        $this->delegate->update(['is_active' => false]);

        $this->engine->processAction($this->instance->fresh(), new WorkflowActionData($this->approver, WorkflowActionType::Approve));

        $this->assertSame(WorkflowStatus::Completed, $this->instance->fresh()->status);
    }

    public function test_deleting_out_of_office_hands_the_step_back(): void
    {
        $schedule = app(SaveOutOfOfficeAction::class)->execute($this->approver, now()->subMinute(), now()->addDays(7), $this->delegate->id);
        $this->assertSame(1, Delegation::query()->active()->count());

        app(DeleteOutOfOfficeAction::class)->execute($this->approver, $schedule->id);

        $this->assertSame(0, Delegation::query()->active()->count());
        $this->assertSame(1, WorkflowAction::query()->where('action', WorkflowActionType::DelegationRevoked)->count());
        $this->engine->processAction($this->instance->fresh(), new WorkflowActionData($this->approver, WorkflowActionType::Approve));
        $this->assertSame(WorkflowStatus::Completed, $this->instance->fresh()->status);
    }

    public function test_delegator_can_take_the_step_back_from_the_request_page(): void
    {
        $this->engine->delegate($this->instance, new DelegationData($this->approver, $this->delegate, now()->addDays(3)));

        Livewire::actingAs($this->approver)
            ->test('submissions.decision', ['submission' => $this->instance->submission->fresh()])
            ->assertSee('Take back')
            ->call('takeBackDelegation');

        $this->assertSame(0, Delegation::query()->active()->count());
        $this->engine->processAction($this->instance->fresh(), new WorkflowActionData($this->approver, WorkflowActionType::Approve));
        $this->assertSame(WorkflowStatus::Completed, $this->instance->fresh()->status);
    }

    public function test_expired_delegations_still_name_the_delegate_in_the_evidence_pack(): void
    {
        $this->engine->delegate($this->instance, new DelegationData($this->approver, $this->delegate, now()->addHour()));
        Carbon::setTestNow(now()->addDays(2));
        $admin = User::factory()->create()->assignRole('admin');

        $files = app(EvidencePackBuilder::class)->files($this->instance->submission->fresh(), $admin);
        $delegations = (string) ($files['delegations.json'] ?? '');
        $workflow = (string) ($files['workflow.json'] ?? '');

        $this->assertStringContainsString($this->delegate->name, $delegations);
        $this->assertStringContainsString($this->delegate->name, $workflow);
    }
}
