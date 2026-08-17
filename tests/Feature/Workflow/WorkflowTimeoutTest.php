<?php

namespace Tests\Feature\Workflow;

use App\Enums\StepType;
use App\Enums\SubmissionStatus;
use App\Enums\TimeoutAction;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Jobs\ProcessEscalation;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkflowTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_auto_approve_timeout_is_audited_and_advances_the_workflow(): void
    {
        Carbon::setTestNow('2026-08-17 09:00:00');
        [$instance, $firstStep] = $this->timedWorkflow(TimeoutAction::AutoApprove, withNextStep: true);
        Carbon::setTestNow('2026-08-17 11:00:01');

        app(ProcessEscalation::class)->handle();

        $this->assertSame(2, $instance->fresh()->currentStep->step_order);
        $this->assertDatabaseHas('workflow_actions', [
            'step_id' => $firstStep->id,
            'actor_id' => null,
            'action' => WorkflowActionType::AutoApprove->value,
        ]);
        $this->assertSame('2026-08-17 11:00:01', $instance->fresh()->step_started_at->format('Y-m-d H:i:s'));
    }

    public function test_auto_reject_timeout_terminates_the_submission(): void
    {
        Carbon::setTestNow('2026-08-17 09:00:00');
        [$instance, $step] = $this->timedWorkflow(TimeoutAction::AutoReject);
        Carbon::setTestNow('2026-08-17 11:00:01');

        app(ProcessEscalation::class)->handle();

        $this->assertSame(WorkflowStatus::Rejected, $instance->fresh()->status);
        $this->assertSame(SubmissionStatus::Rejected, $instance->submission->fresh()->status);
        $this->assertDatabaseHas('workflow_actions', [
            'step_id' => $step->id,
            'action' => WorkflowActionType::AutoReject->value,
        ]);
    }

    public function test_escalation_timeout_moves_to_the_next_configured_step(): void
    {
        Carbon::setTestNow('2026-08-17 09:00:00');
        [$instance, $step] = $this->timedWorkflow(TimeoutAction::Escalate, withNextStep: true);
        Carbon::setTestNow('2026-08-17 11:00:01');

        app(WorkflowEngine::class)->processTimeout($instance);

        $this->assertSame(2, $instance->fresh()->currentStep->step_order);
        $this->assertDatabaseHas('workflow_actions', [
            'step_id' => $step->id,
            'actor_id' => null,
            'action' => WorkflowActionType::Escalate->value,
        ]);
    }

    public function test_not_yet_due_instance_is_unchanged(): void
    {
        Carbon::setTestNow('2026-08-17 09:00:00');
        [$instance] = $this->timedWorkflow(TimeoutAction::AutoApprove, withNextStep: true);
        Carbon::setTestNow('2026-08-17 10:59:59');

        app(ProcessEscalation::class)->handle();

        $this->assertSame(1, $instance->fresh()->currentStep->step_order);
        $this->assertSame(0, WorkflowAction::query()->count());
        $this->assertFalse($instance->fresh()->isOverdue());
    }

    public function test_resume_restarts_the_step_sla_clock(): void
    {
        Carbon::setTestNow('2026-08-17 09:00:00');
        [$instance] = $this->timedWorkflow(TimeoutAction::AutoApprove, withNextStep: true);
        $approver = $instance->currentStep->assignedUsers()->firstOrFail();
        Carbon::setTestNow('2026-08-17 10:00:00');
        app(WorkflowEngine::class)->processAction(
            $instance,
            $approver,
            WorkflowActionType::RequestInformation,
            'Please clarify.',
        );
        Carbon::setTestNow('2026-08-18 09:00:00');

        app(WorkflowEngine::class)->resume($instance);

        $this->assertSame('2026-08-18 09:00:00', $instance->fresh()->step_started_at->format('Y-m-d H:i:s'));
        $this->assertFalse($instance->fresh()->isOverdue());
    }

    /** @return array{0: WorkflowInstance, 1: WorkflowTemplateStep} */
    private function timedWorkflow(TimeoutAction $action, bool $withNextStep = false): array
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        $step = WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Sequential,
            'assignee_ids' => [$approver->id],
            'timeout_hours' => 2,
            'timeout_action' => $action,
        ]);

        if ($withNextStep) {
            WorkflowTemplateStep::factory()->forTemplate($template, 2)->create([
                'assignee_ids' => [User::factory()->create()->id],
            ]);
        }

        return [app(WorkflowEngine::class)->initialize(Submission::factory()->create()), $step];
    }
}
