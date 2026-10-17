<?php

namespace Tests\Feature\Workflow;

use App\DTOs\WorkflowActionData;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\SubmissionDecidedNotification;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class CollaborationActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_information_request_pauses_and_resume_allows_the_approver_to_decide(): void
    {
        Notification::fake();
        $submitter = User::factory()->create();
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        $engine = app(WorkflowEngine::class);
        $instance = $engine->initialize($submission);

        $engine->processAction(
            $instance,
            new WorkflowActionData(
                $approver,
                WorkflowActionType::RequestInformation,
                'Please attach the supporting schedule.',
            ),
        );

        $this->assertSame(WorkflowStatus::OnHold, $instance->fresh()->status);
        $this->assertSame(SubmissionStatus::AwaitingInformation, $submission->fresh()->status);
        Notification::assertSentTo(
            $submitter,
            SubmissionDecidedNotification::class,
            fn ($notification) => $notification->decision === SubmissionStatus::AwaitingInformation,
        );

        $engine->resume($instance);
        $engine->processAction($instance, new WorkflowActionData($approver, WorkflowActionType::Approve));

        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);
    }

    public function test_escalation_skips_to_the_next_step_and_notifies_its_approver(): void
    {
        Notification::fake();
        $first = User::factory()->create();
        $next = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$first, $next])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        app(WorkflowEngine::class)->processAction(
            $instance,
            new WorkflowActionData(
                $first,
                WorkflowActionType::Escalate,
                'Executive review is required.',
            ),
        );

        $this->assertSame(2, $instance->fresh()->currentStep->step_order);
        $this->assertDatabaseHas('workflow_actions', [
            'instance_id' => $instance->id,
            'actor_id' => $first->id,
            'action' => WorkflowActionType::Escalate->value,
        ]);
        Notification::assertSentTo($next, ActionRequiredNotification::class);
    }

    public function test_last_step_cannot_be_escalated_into_automatic_completion(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        try {
            app(WorkflowEngine::class)->processAction(
                $instance,
                new WorkflowActionData(
                    $approver,
                    WorkflowActionType::Escalate,
                    'No higher step exists.',
                ),
            );
            $this->fail('Escalating the final step must be rejected.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('no later step', $exception->getMessage());
        }

        $this->assertSame(WorkflowStatus::Active, $instance->fresh()->status);
        $this->assertSame(0, WorkflowAction::query()->count());
    }

    public function test_information_request_and_response_flow_is_available_in_the_submission_ui(): void
    {
        Notification::fake();
        $submitter = User::factory()->create();
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        Livewire::actingAs($approver)
            ->test('submissions.decision', ['submission' => $submission])
            ->assertSee('Request information')
            ->set('actionComment', 'Upload the signed schedule.')
            ->call('act', WorkflowActionType::RequestInformation->value)
            ->assertHasNoErrors();

        Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->assertSee('Resubmit for approval')
            ->set('resubmissionComment', 'The signed schedule is now available in the comments.')
            ->call('resubmit')
            ->assertHasNoErrors();

        $this->assertSame(WorkflowStatus::Active, $submission->workflowInstance()->first()->status);
        $this->assertSame(SubmissionStatus::InReview, $submission->fresh()->status);
        $this->assertDatabaseHas('comments', [
            'commentable_id' => $submission->id,
            'author_id' => $submitter->id,
            'body' => 'The signed schedule is now available in the comments.',
        ]);
    }
}
