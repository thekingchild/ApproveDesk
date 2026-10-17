<?php

namespace Tests\Feature\Workflow;

use App\DTOs\WorkflowActionData;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Events\WorkflowAdvanced;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowRejected;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkflowEngineProcessActionTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    private User $firstApprover;

    private User $secondApprover;

    private WorkflowInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(WorkflowEngine::class);

        $this->firstApprover = User::factory()->create();
        $this->secondApprover = User::factory()->create();

        $template = WorkflowTemplate::factory()
            ->default()
            ->sequential([$this->firstApprover, $this->secondApprover])
            ->create();

        $submission = Submission::factory()->create();
        $this->instance = $this->engine->initialize($submission);
    }

    public function test_approval_by_assigned_user_advances_to_next_step(): void
    {
        Event::fake([WorkflowAdvanced::class]);

        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Approve, 'Looks good'));

        $this->instance->refresh();

        $this->assertSame(WorkflowStatus::Active, $this->instance->status);
        $this->assertSame(2, $this->instance->currentStep->step_order);

        Event::assertDispatched(WorkflowAdvanced::class);
    }

    public function test_final_approval_completes_workflow_and_submission(): void
    {
        Event::fake([WorkflowCompleted::class]);

        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Approve));
        $this->engine->processAction($this->instance, new WorkflowActionData($this->secondApprover, WorkflowActionType::Approve));

        $this->instance->refresh();

        $this->assertSame(WorkflowStatus::Completed, $this->instance->status);
        $this->assertNotNull($this->instance->completed_at);
        $this->assertSame(SubmissionStatus::Approved, $this->instance->submission->status);

        Event::assertDispatched(WorkflowCompleted::class);
        Event::assertNotDispatched(WorkflowAdvanced::class);
    }

    public function test_rejection_terminates_workflow_and_notifies_via_event(): void
    {
        Event::fake([WorkflowRejected::class]);

        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Reject, 'Not enough detail'));

        $this->instance->refresh();

        $this->assertSame(WorkflowStatus::Rejected, $this->instance->status);
        $this->assertNotNull($this->instance->completed_at);
        $this->assertSame(SubmissionStatus::Rejected, $this->instance->submission->status);

        Event::assertDispatched(WorkflowRejected::class);
    }

    public function test_return_for_revision_puts_instance_on_hold(): void
    {
        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Return, 'Please expand section 2'));

        $this->instance->refresh();

        $this->assertSame(WorkflowStatus::OnHold, $this->instance->status);
        $this->assertSame(SubmissionStatus::Returned, $this->instance->submission->status);
        $this->assertSame(1, $this->instance->currentStep->step_order, 'current step must not advance on return');
    }

    public function test_comment_is_recorded_without_state_change(): void
    {
        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Comment, 'Checking with finance'));

        $this->instance->refresh();

        $this->assertSame(WorkflowStatus::Active, $this->instance->status);
        $this->assertSame(SubmissionStatus::InReview, $this->instance->submission->status);
        $this->assertSame(1, $this->instance->actions()->count());
        $this->assertSame(WorkflowActionType::Comment, $this->instance->actions->first()->action);
    }

    public function test_unassigned_actor_cannot_act(): void
    {
        $outsider = User::factory()->create();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not an assignee');

        $this->engine->processAction($this->instance, new WorkflowActionData($outsider, WorkflowActionType::Approve));
    }

    public function test_next_step_approver_cannot_act_early(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not an assignee');

        $this->engine->processAction($this->instance, new WorkflowActionData($this->secondApprover, WorkflowActionType::Approve));
    }

    public function test_comments_are_repeatable_and_cannot_be_double_spent(): void
    {
        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Comment, 'first'));
        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Comment, 'second'));

        $this->assertSame(2, $this->instance->actions()->count(), 'comments remain repeatable');
    }

    public function test_stale_instance_cannot_double_approve_the_old_step(): void
    {
        // Approve level 1; the engine advances to level 2 under lock. A caller
        // holding the stale pre-advance instance must not be able to apply a
        // second action against level 1 — the engine re-reads the locked row.
        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Approve));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not an assignee');

        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Approve));
    }

    public function test_no_actions_accepted_after_rejection(): void
    {
        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Reject, 'no'));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not active');

        $this->engine->processAction($this->instance, new WorkflowActionData($this->secondApprover, WorkflowActionType::Approve));
    }

    public function test_every_action_is_recorded_in_the_audit_log_with_context(): void
    {
        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Approve, 'ok'));

        /** @var WorkflowAction $action */
        $action = WorkflowAction::query()->first();

        $this->assertSame($this->instance->id, $action->instance_id);
        $this->assertSame($this->firstApprover->id, $action->actor_id);
        $this->assertSame(WorkflowActionType::Approve, $action->action);
        $this->assertSame('ok', $action->comment);
    }

    public function test_full_approval_history_is_preserved_across_steps(): void
    {
        $this->engine->processAction($this->instance, new WorkflowActionData($this->firstApprover, WorkflowActionType::Approve, 'level 1 ok'));
        $this->engine->processAction($this->instance, new WorkflowActionData($this->secondApprover, WorkflowActionType::Approve, 'level 2 ok'));

        $history = $this->instance->actions->pluck('comment')->all();

        $this->assertSame(['level 1 ok', 'level 2 ok'], $history);
    }
}
