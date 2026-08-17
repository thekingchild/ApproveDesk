<?php

namespace Tests\Feature\Workflow;

use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\SubmissionPriority;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_steps_are_audited_and_advance_to_the_next_manual_step(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        $auto = WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Policy automation',
            'step_type' => StepType::Auto,
            'assignee_ids' => [],
        ]);
        $manual = WorkflowTemplateStep::factory()->forTemplate($template, 2)->create([
            'assignee_ids' => [$approver->id],
        ]);

        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        $this->assertSame($manual->id, $instance->fresh()->current_step_id);
        $this->assertDatabaseHas('workflow_actions', [
            'instance_id' => $instance->id,
            'step_id' => $auto->id,
            'actor_id' => null,
            'action' => WorkflowActionType::AutoApprove->value,
        ]);
    }

    public function test_conditional_step_routes_using_submission_fields_and_metadata(): void
    {
        $standardApprover = User::factory()->create();
        $executiveApprover = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        $conditional = WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'High value routing',
            'step_type' => StepType::Conditional,
            'assignee_ids' => [],
            'conditions' => [
                'match' => 'all',
                'rules' => [
                    ['field' => 'priority', 'operator' => 'in', 'value' => ['high', 'urgent']],
                    ['field' => 'metadata.amount', 'operator' => 'gte', 'value' => 10000],
                ],
                'true_step' => 3,
                'false_step' => 2,
            ],
        ]);
        $standard = WorkflowTemplateStep::factory()->forTemplate($template, 2)->create([
            'assignee_ids' => [$standardApprover->id],
        ]);
        $executive = WorkflowTemplateStep::factory()->forTemplate($template, 3)->create([
            'assignee_ids' => [$executiveApprover->id],
        ]);
        $submission = Submission::factory()->priority(SubmissionPriority::High)->create([
            'metadata' => ['amount' => 12500],
        ]);

        $instance = app(WorkflowEngine::class)->initialize($submission);

        $this->assertSame($executive->id, $instance->fresh()->current_step_id);
        $this->assertNotSame($standard->id, $instance->current_step_id);
        $this->assertDatabaseHas('workflow_actions', [
            'instance_id' => $instance->id,
            'step_id' => $conditional->id,
            'action' => WorkflowActionType::ConditionMatched->value,
        ]);
    }

    public function test_false_condition_can_route_to_a_different_forward_step(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        $conditional = WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Conditional,
            'assignee_ids' => [],
            'conditions' => [
                'rules' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'urgent']],
                'true_step' => 3,
                'false_step' => 2,
            ],
        ]);
        $expected = WorkflowTemplateStep::factory()->forTemplate($template, 2)->create([
            'assignee_ids' => [$approver->id],
        ]);
        WorkflowTemplateStep::factory()->forTemplate($template, 3)->create([
            'assignee_ids' => [$approver->id],
        ]);

        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        $this->assertSame($expected->id, $instance->fresh()->current_step_id);
        $this->assertDatabaseHas('workflow_actions', [
            'step_id' => $conditional->id,
            'action' => WorkflowActionType::ConditionNotMatched->value,
        ]);
    }

    public function test_automatic_steps_can_complete_a_workflow_without_an_actor(): void
    {
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Auto,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => [],
        ]);

        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);
        $this->assertNull($instance->fresh()->current_step_id);
        $this->assertSame(1, WorkflowAction::query()->count());
    }

    public function test_manual_approval_resolves_a_chain_of_conditional_and_auto_steps(): void
    {
        $firstApprover = User::factory()->create();
        $finalApprover = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'assignee_ids' => [$firstApprover->id],
        ]);
        $conditional = WorkflowTemplateStep::factory()->forTemplate($template, 2)->create([
            'step_type' => StepType::Conditional,
            'assignee_ids' => [],
            'conditions' => [
                'rules' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'urgent']],
                'true_step' => 3,
                'false_step' => 4,
            ],
        ]);
        $auto = WorkflowTemplateStep::factory()->forTemplate($template, 3)->create([
            'step_type' => StepType::Auto,
            'assignee_ids' => [],
        ]);
        $final = WorkflowTemplateStep::factory()->forTemplate($template, 4)->create([
            'assignee_ids' => [$finalApprover->id],
        ]);
        $submission = Submission::factory()->priority(SubmissionPriority::Urgent)->create();
        $instance = app(WorkflowEngine::class)->initialize($submission);

        app(WorkflowEngine::class)->processAction($instance, $firstApprover, WorkflowActionType::Approve);

        $this->assertSame($final->id, $instance->fresh()->current_step_id);
        $this->assertDatabaseHas('workflow_actions', [
            'step_id' => $conditional->id,
            'action' => WorkflowActionType::ConditionMatched->value,
        ]);
        $this->assertDatabaseHas('workflow_actions', [
            'step_id' => $auto->id,
            'action' => WorkflowActionType::AutoApprove->value,
        ]);
    }

    public function test_invalid_or_backward_conditional_targets_are_rejected(): void
    {
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Conditional,
            'assignee_ids' => [],
            'conditions' => [
                'rules' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'normal']],
                'true_step' => 1,
                'false_step' => null,
            ],
        ]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('forward step');

        app(WorkflowEngine::class)->initialize(Submission::factory()->create());
    }
}
