<?php

namespace Tests\Feature\Workflow;

use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\WorkflowActionType;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\ApproverWorkbench;
use App\Services\WorkflowEngine;
use App\Support\SeparationOfDuties;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-ARC-01: the request page, the approvals inbox, and batch approval must
 * agree with what WorkflowEngine::processAction() will accept.
 */
class ApprovalEligibilityParityTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    private User $first;

    private User $second;

    private Submission $submission;

    private WorkflowInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(WorkflowEngine::class);
        $this->first = User::factory()->create();
        $this->second = User::factory()->create();

        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Parallel,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => [$this->first->id, $this->second->id],
        ]);
        $this->submission = Submission::factory()->create();
        $this->instance = $this->engine->initialize($this->submission);
    }

    public function test_rv_arc_01_decision_panel_hides_after_the_viewer_approves_an_open_parallel_step(): void
    {
        $this->engine->processAction($this->instance, $this->first, WorkflowActionType::Approve);

        $this->assertRequestPage($this->first, canAct: false, canDelegate: false);
        $this->assertRequestPage($this->second, canAct: true, canDelegate: true);
        $this->assertWorkbenchAgrees($this->first, false);
        $this->assertWorkbenchAgrees($this->second, true);
    }

    public function test_rv_arc_01_approve_with_conditions_counts_as_the_viewers_decision(): void
    {
        $this->engine->processAction($this->instance, $this->first, WorkflowActionType::ApproveWithConditions, 'Within budget only.');

        $this->assertRequestPage($this->first, canAct: false, canDelegate: false);
        $this->assertWorkbenchAgrees($this->first, false);
    }

    public function test_rv_arc_01_separation_of_duties_hides_the_decision_from_the_requester_everywhere(): void
    {
        Setting::put(SeparationOfDuties::SETTING, '1');
        $submission = Submission::factory()->create(['submitter_id' => $this->first->id]);
        $instance = $this->engine->initialize($submission);

        $this->assertFalse($this->engine->canAct($instance, $this->first));
        $this->assertFalse(app(ApproverWorkbench::class)->canAct($instance->fresh(), $this->first));
        $this->assertFalse($this->needsMe($this->first, $instance));
    }

    public function test_rv_arc_01_delegation_to_a_since_deactivated_delegate_gives_the_step_back(): void
    {
        $delegate = User::factory()->create();
        $this->engine->delegate($this->instance, $this->first, $delegate, now()->addDay());

        $this->assertRequestPage($this->first, canAct: false, canDelegate: false);
        $this->assertWorkbenchAgrees($this->first, false);

        $delegate->forceFill(['is_active' => false])->save();

        $this->assertRequestPage($this->first, canAct: true, canDelegate: true);
        $this->assertWorkbenchAgrees($this->first, true);
    }

    public function test_rv_arc_01_nobody_can_act_or_delegate_once_the_workflow_is_finished(): void
    {
        $this->engine->processAction($this->instance, $this->first, WorkflowActionType::Approve);
        $this->engine->processAction($this->instance, $this->second, WorkflowActionType::Approve);

        $this->assertRequestPage($this->second, canAct: false, canDelegate: false);
        $this->assertFalse($this->engine->canDelegate($this->instance->fresh(), $this->second));
    }

    private function assertRequestPage(User $viewer, bool $canAct, bool $canDelegate): void
    {
        Livewire::actingAs($viewer)
            ->test('pages::submissions.show', ['submission' => $this->submission->fresh()])
            ->assertSet('canAct', $canAct)
            ->assertSet('canDelegate', $canDelegate);
    }

    private function assertWorkbenchAgrees(User $viewer, bool $expected): void
    {
        $instance = $this->instance->fresh();

        $this->assertSame($expected, $this->engine->canAct($instance, $viewer), 'engine');
        $this->assertSame($expected, app(ApproverWorkbench::class)->canAct($instance, $viewer), 'batch approval');
        $this->assertSame($expected, $this->needsMe($viewer, $instance), 'inbox "needs me"');
    }

    private function needsMe(User $viewer, WorkflowInstance $instance): bool
    {
        $item = collect(app(ApproverWorkbench::class)->itemsFor($viewer))
            ->first(fn (array $item): bool => $item['instance']->is($instance));

        return $item !== null && $item['needs_me'];
    }
}
