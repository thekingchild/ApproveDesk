<?php

namespace Tests\Feature\Workflow;

use App\DTOs\DelegationData;
use App\DTOs\WorkflowActionData;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-ENG-03: a delegate fills the delegator's seat, so the two can never
 * count as two approvals on an All or Quorum step.
 */
class DelegatedSeatTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->engine = app(WorkflowEngine::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_delegator_cannot_add_a_second_quorum_approval_after_their_delegate(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        $delegate = User::factory()->create();
        $instance = $this->instanceFor(StepType::Quorum, [$a, $b, $c], quorum: 2);

        $this->engine->delegate($instance, new DelegationData($a, $delegate, now()->addHour()));
        $this->engine->processAction($instance, new WorkflowActionData($delegate, WorkflowActionType::Approve));
        Carbon::setTestNow(now()->addHours(2));

        try {
            $this->engine->processAction($instance, new WorkflowActionData($a, WorkflowActionType::Approve));
            $this->fail('The delegator counted a second time.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('already acted', $exception->getMessage());
        }
        $this->assertSame(WorkflowStatus::Active, $instance->fresh()->status);

        $this->engine->processAction($instance, new WorkflowActionData($b, WorkflowActionType::Approve));
        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);
    }

    public function test_all_step_needs_every_seat_even_with_a_delegate(): void
    {
        [$a, $b] = User::factory()->count(2)->create()->all();
        $delegate = User::factory()->create();
        $instance = $this->instanceFor(StepType::Parallel, [$a, $b]);

        $this->engine->delegate($instance, new DelegationData($a, $delegate, now()->addHour()));
        $this->engine->processAction($instance, new WorkflowActionData($delegate, WorkflowActionType::Approve));

        $this->assertSame(WorkflowStatus::Active, $instance->fresh()->status);

        $this->engine->processAction($instance, new WorkflowActionData($b, WorkflowActionType::Approve));
        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);
    }

    public function test_delegate_decision_records_and_shows_whose_seat_it_filled(): void
    {
        $approver = User::factory()->create(['name' => 'Ada Approver']);
        $delegate = User::factory()->create(['name' => 'Dee Delegate']);
        $instance = $this->instanceFor(StepType::Sequential, [$approver]);

        $this->engine->delegate($instance, new DelegationData($approver, $delegate, now()->addHour()));
        $this->engine->processAction($instance, new WorkflowActionData($delegate, WorkflowActionType::Approve));

        $approval = WorkflowAction::query()->where('action', 'approve')->sole();
        $this->assertSame($delegate->id, $approval->actor_id);
        $this->assertSame($approver->id, $approval->on_behalf_of_id);

        $viewer = User::factory()->create()->assignRole('admin');
        Livewire::actingAs($viewer)
            ->test('pages::submissions.show', ['submission' => $instance->submission->fresh()])
            ->assertSee('on behalf of Ada Approver');
    }

    public function test_direct_decisions_fill_the_actors_own_seat(): void
    {
        $approver = User::factory()->create();
        $instance = $this->instanceFor(StepType::Sequential, [$approver]);

        $this->engine->processAction($instance, new WorkflowActionData($approver, WorkflowActionType::Approve));

        $this->assertNull(WorkflowAction::query()->where('action', 'approve')->sole()->on_behalf_of_id);
    }

    /**
     * @param  array<int, User>  $assignees
     */
    private function instanceFor(StepType $type, array $assignees, ?int $quorum = null): WorkflowInstance
    {
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => $type,
            'quorum_count' => $quorum,
            'allow_delegation' => true,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => array_map(fn (User $user): int => $user->id, $assignees),
        ]);

        return $this->engine->initialize(Submission::factory()->create());
    }
}
