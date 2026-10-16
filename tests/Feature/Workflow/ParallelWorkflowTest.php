<?php

namespace Tests\Feature\Workflow;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Events\WorkflowAdvanced;
use App\Events\WorkflowCompleted;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Notifications\ActionRequiredNotification;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ParallelWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    /** @var array<int, User> */
    private array $parallelApprovers;

    private User $finalApprover;

    private WorkflowInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(WorkflowEngine::class);
        $this->parallelApprovers = User::factory()->count(3)->create()->all();
        $this->finalApprover = User::factory()->create();

        $template = WorkflowTemplate::factory()->default()->create();

        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Parallel review',
            'step_type' => StepType::Parallel,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => collect($this->parallelApprovers)->pluck('id')->all(),
        ]);
        WorkflowTemplateStep::factory()->forTemplate($template, 2)->create([
            'name' => 'Final review',
            'step_type' => StepType::Sequential,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => [$this->finalApprover->id],
        ]);

        $this->instance = $this->engine->initialize(Submission::factory()->create());
    }

    public function test_parallel_step_waits_until_every_assignee_approves(): void
    {
        Event::fake([WorkflowAdvanced::class, WorkflowCompleted::class]);

        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[0],
            WorkflowActionType::Approve,
            'First approval',
        );
        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[1],
            WorkflowActionType::Approve,
            'Second approval',
        );

        $this->instance->refresh();
        $this->assertSame(1, $this->instance->currentStep->step_order);
        $this->assertSame(WorkflowStatus::Active, $this->instance->status);
        Event::assertNotDispatched(WorkflowAdvanced::class);

        $timeline = Livewire::actingAs($this->instance->submission->submitter)
            ->test('pages::submissions.show', ['submission' => $this->instance->submission])
            ->get('timeline');
        $this->assertSame('active', $timeline[0]['state']);

        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[2],
            WorkflowActionType::Approve,
            'Third approval',
        );

        $this->instance->refresh();
        $this->assertSame(2, $this->instance->currentStep->step_order);
        $this->assertSame(
            collect($this->parallelApprovers)->pluck('id')->all(),
            $this->instance->actions()->pluck('actor_id')->all(),
        );
        Event::assertDispatchedTimes(WorkflowAdvanced::class, 1);

        $this->engine->processAction(
            $this->instance,
            $this->finalApprover,
            WorkflowActionType::Approve,
        );

        $this->instance->refresh();
        $this->assertSame(WorkflowStatus::Completed, $this->instance->status);
        $this->assertSame(SubmissionStatus::Approved, $this->instance->submission->status);
        Event::assertDispatched(WorkflowCompleted::class);
    }

    public function test_parallel_approval_cannot_be_submitted_twice_by_the_same_actor(): void
    {
        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[0],
            WorkflowActionType::Approve,
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('already acted');

        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[0],
            WorkflowActionType::Approve,
        );
    }

    public function test_parallel_notifications_target_all_assignees_then_the_next_step(): void
    {
        Notification::fake();

        $submission = Submission::factory()->draft()->create();
        app(SubmitSubmissionAction::class)->execute($submission);

        foreach ($this->parallelApprovers as $approver) {
            Notification::assertSentTo($approver, ActionRequiredNotification::class);
        }
        Notification::assertNotSentTo($this->finalApprover, ActionRequiredNotification::class);

        Notification::fake();
        $parallelInstance = $submission->workflowInstance()->firstOrFail();

        $this->engine->processAction(
            $parallelInstance,
            $this->parallelApprovers[0],
            WorkflowActionType::Approve,
        );
        Notification::assertNothingSent();

        $this->engine->processAction(
            $parallelInstance,
            $this->parallelApprovers[1],
            WorkflowActionType::Approve,
        );
        Notification::assertNothingSent();

        $this->engine->processAction(
            $parallelInstance,
            $this->parallelApprovers[2],
            WorkflowActionType::Approve,
        );
        Notification::assertSentTo($this->finalApprover, ActionRequiredNotification::class);
    }

    public function test_rejection_by_any_parallel_assignee_terminates_the_workflow(): void
    {
        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[0],
            WorkflowActionType::Approve,
        );
        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[1],
            WorkflowActionType::Reject,
            'A required control failed.',
        );

        $this->instance->refresh();
        $this->assertSame(WorkflowStatus::Rejected, $this->instance->status);
        $this->assertSame(SubmissionStatus::Rejected, $this->instance->submission->status);
        $this->assertSame(2, $this->instance->actions()->count());
    }

    public function test_return_and_resume_start_a_new_review_round_for_the_step(): void
    {
        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[0],
            WorkflowActionType::Approve,
        );
        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[1],
            WorkflowActionType::Return,
            'Please revise the totals.',
        );

        $this->engine->resume($this->instance);
        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[2],
            WorkflowActionType::Approve,
        );

        $this->instance->refresh();
        $this->assertSame(1, $this->instance->currentStep->step_order);

        // RV-ENG-04: the first-round approval no longer counts, so the earlier
        // approver decides again on the revised request instead of being a duplicate.
        $this->engine->processAction(
            $this->instance,
            $this->parallelApprovers[0],
            WorkflowActionType::Approve,
        );

        $this->instance->refresh();
        $this->assertSame(1, $this->instance->currentStep->step_order);
    }
}
