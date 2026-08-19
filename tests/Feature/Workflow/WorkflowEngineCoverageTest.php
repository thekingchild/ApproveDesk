<?php

namespace Tests\Feature\Workflow;

use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowEngineCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_outcome_is_rejected(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not defined');
        app(WorkflowEngine::class)->processAction($instance, $approver, WorkflowActionType::Approve, 'note', 'missing_outcome');
    }

    public function test_system_action_types_cannot_be_submitted_by_actors(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        $this->expectException(WorkflowException::class);
        app(WorkflowEngine::class)->processAction($instance, $approver, WorkflowActionType::AutoApprove);
    }

    public function test_active_instance_with_no_current_step_rejects_the_actor(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        $instance->update(['current_step_id' => null]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not an assignee');
        app(WorkflowEngine::class)->processAction($instance->fresh(), $approver, WorkflowActionType::Approve);
    }

    public function test_timeout_is_a_no_op_when_the_deadline_has_not_elapsed(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $template->steps->first()->updateQuietly(['timeout_hours' => 24, 'timeout_action' => 'auto_approve']);
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        $unchanged = app(WorkflowEngine::class)->processTimeout($instance);

        $this->assertSame(WorkflowStatus::Active, $unchanged->status);
        $this->assertSame(0, $instance->actions()->count());
    }

    public function test_resume_rejects_instances_that_are_not_on_hold(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not active');
        app(WorkflowEngine::class)->resume($instance);
    }

    public function test_missing_default_template_is_reported_as_no_template(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No active workflow template');
        app(WorkflowEngine::class)->initialize(Submission::factory()->create());
    }

    public function test_unresolved_contextual_assignees_fail_when_the_step_becomes_current(): void
    {
        $requester = User::factory()->create(['manager_id' => null]);
        $template = WorkflowTemplate::factory()->default()->sequential([User::factory()->create()])->create();
        $template->steps->first()->updateQuietly([
            'assignee_type' => AssigneeType::RequesterManager,
            'assignee_ids' => [],
        ]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('no resolvable assignees');
        app(WorkflowEngine::class)->initialize(Submission::factory()->for($requester, 'submitter')->create());
    }

    public function test_manual_action_on_a_step_without_completion_rule_is_rejected(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        $template->steps->first()->updateQuietly([
            'step_type' => StepType::Auto,
            'completion_rule' => null,
        ]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not implemented');
        app(WorkflowEngine::class)->processAction($instance->fresh(), $approver, WorkflowActionType::Approve);
    }

    public function test_invalid_conditional_configuration_is_rejected(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Conditional,
            'assignee_ids' => [],
            'conditions' => ['rules' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'high']]],
        ]);
        WorkflowTemplateStep::factory()->forTemplate($template, 2)->create([
            'assignee_ids' => [$approver->id],
        ]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('true_step and false_step');
        app(WorkflowEngine::class)->initialize(Submission::factory()->create());
    }
}
