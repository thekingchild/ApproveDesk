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
use App\Models\WorkflowAction;
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

class QuorumWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    /** @var array<int, User> */
    private array $quorumApprovers;

    private User $finalApprover;

    private WorkflowTemplateStep $quorumStep;

    private WorkflowInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(WorkflowEngine::class);
        $this->quorumApprovers = User::factory()->count(3)->create()->all();
        $this->finalApprover = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();

        $this->quorumStep = WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Committee quorum',
            'step_type' => StepType::Quorum,
            'quorum_count' => 2,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => collect($this->quorumApprovers)->pluck('id')->all(),
        ]);
        WorkflowTemplateStep::factory()->forTemplate($template, 2)->create([
            'name' => 'Final review',
            'step_type' => StepType::Sequential,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => [$this->finalApprover->id],
        ]);

        $this->instance = $this->engine->initialize(Submission::factory()->create());
    }

    public function test_quorum_advances_when_the_required_number_approve(): void
    {
        Event::fake([WorkflowAdvanced::class, WorkflowCompleted::class]);

        $this->engine->processAction(
            $this->instance,
            $this->quorumApprovers[0],
            WorkflowActionType::Approve,
        );

        $this->instance->refresh();
        $this->assertSame(1, $this->instance->currentStep->step_order);
        Event::assertNotDispatched(WorkflowAdvanced::class);

        Livewire::actingAs($this->instance->submission->submitter)
            ->test('pages::submissions.show', ['submission' => $this->instance->submission])
            ->assertSee('1 / 2 approvals');

        $this->engine->processAction(
            $this->instance,
            $this->quorumApprovers[1],
            WorkflowActionType::Approve,
        );

        $this->instance->refresh();
        $this->assertSame(2, $this->instance->currentStep->step_order);
        $this->assertSame(2, $this->instance->actions()->count());
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

    public function test_remaining_assignees_cannot_act_after_quorum_advances(): void
    {
        $this->engine->processAction($this->instance, $this->quorumApprovers[0], WorkflowActionType::Approve);
        $this->engine->processAction($this->instance, $this->quorumApprovers[1], WorkflowActionType::Approve);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not an assignee');

        $this->engine->processAction($this->instance, $this->quorumApprovers[2], WorkflowActionType::Approve);
    }

    public function test_any_quorum_assignee_can_reject_before_threshold_is_met(): void
    {
        $this->engine->processAction($this->instance, $this->quorumApprovers[0], WorkflowActionType::Approve);
        $this->engine->processAction(
            $this->instance,
            $this->quorumApprovers[2],
            WorkflowActionType::Reject,
            'Committee rejected the request.',
        );

        $this->instance->refresh();
        $this->assertSame(WorkflowStatus::Rejected, $this->instance->status);
        $this->assertSame(SubmissionStatus::Rejected, $this->instance->submission->status);
    }

    public function test_impossible_quorum_is_rejected_without_audit_mutation(): void
    {
        foreach ([null, 0, 4] as $invalidQuorum) {
            $this->quorumStep->update(['quorum_count' => $invalidQuorum]);

            try {
                $this->engine->processAction(
                    $this->instance,
                    $this->quorumApprovers[0],
                    WorkflowActionType::Approve,
                );

                $this->fail('An impossible quorum must not be accepted.');
            } catch (WorkflowException $exception) {
                $this->assertStringContainsString('requires between 1 and 3 active assignees', $exception->getMessage());
            }

            $this->assertSame(0, WorkflowAction::query()->where('instance_id', $this->instance->id)->count());
            $this->assertSame(1, $this->instance->fresh()->currentStep->step_order);
        }
    }

    public function test_quorum_notifications_wait_for_the_threshold(): void
    {
        Notification::fake();

        $submission = Submission::factory()->draft()->create();
        app(SubmitSubmissionAction::class)->execute($submission);

        foreach ($this->quorumApprovers as $approver) {
            Notification::assertSentTo($approver, ActionRequiredNotification::class);
        }

        Notification::fake();
        $instance = $submission->workflowInstance()->firstOrFail();
        $this->engine->processAction($instance, $this->quorumApprovers[0], WorkflowActionType::Approve);
        Notification::assertNothingSent();

        $this->engine->processAction($instance, $this->quorumApprovers[1], WorkflowActionType::Approve);
        Notification::assertSentTo($this->finalApprover, ActionRequiredNotification::class);
    }
}
