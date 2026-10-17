<?php

namespace Tests\Feature;

use App\DTOs\WorkflowActionData;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\SubmissionDecidedNotification;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $firstApprover;

    private User $secondApprover;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->firstApprover = User::factory()->create()->assignRole('approver');
        $this->secondApprover = User::factory()->create()->assignRole('approver');

        WorkflowTemplate::factory()
            ->default()
            ->sequential([$this->firstApprover, $this->secondApprover])
            ->create();
    }

    private function startSubmission(): Submission
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        return $submission;
    }

    public function test_step_advances_notify_the_next_approver(): void
    {
        $submission = $this->startSubmission();
        $engine = app(WorkflowEngine::class);

        $engine->processAction(
            $submission->workflowInstance()->first(),
            new WorkflowActionData(
                $this->firstApprover,
                WorkflowActionType::Approve,
            ),
        );

        Notification::assertSentTo([$this->secondApprover], ActionRequiredNotification::class);
    }

    public function test_full_approval_notifies_the_submitter(): void
    {
        $submission = $this->startSubmission();
        $engine = app(WorkflowEngine::class);

        $engine->processAction($submission->workflowInstance()->first(), new WorkflowActionData($this->firstApprover, WorkflowActionType::Approve));
        Notification::fake(); // reset: only assert the final decision below

        $engine->processAction($submission->workflowInstance()->first(), new WorkflowActionData($this->secondApprover, WorkflowActionType::Approve));

        Notification::assertSentTo(
            [$this->submitter],
            SubmissionDecidedNotification::class,
            fn (SubmissionDecidedNotification $notification) => $notification->decision === SubmissionStatus::Approved,
        );
    }

    public function test_rejection_notifies_the_submitter_with_the_decision(): void
    {
        $submission = $this->startSubmission();

        app(WorkflowEngine::class)->processAction(
            $submission->workflowInstance()->first(),
            new WorkflowActionData(
                $this->firstApprover,
                WorkflowActionType::Reject,
                'Insufficient detail.',
            ),
        );

        Notification::assertSentTo(
            [$this->submitter],
            SubmissionDecidedNotification::class,
            fn (SubmissionDecidedNotification $notification) => $notification->decision === SubmissionStatus::Rejected,
        );
    }

    public function test_return_notifies_the_submitter_and_resubmission_renotifies_approvers(): void
    {
        $submission = $this->startSubmission();
        $engine = app(WorkflowEngine::class);

        $engine->processAction(
            $submission->workflowInstance()->first(),
            new WorkflowActionData(
                $this->firstApprover,
                WorkflowActionType::Return,
                'Extend section 2.',
            ),
        );

        Notification::assertSentTo(
            [$this->submitter],
            SubmissionDecidedNotification::class,
            fn (SubmissionDecidedNotification $notification) => $notification->decision === SubmissionStatus::Returned,
        );

        Notification::fake();

        $engine->resume($submission->workflowInstance()->first());

        Notification::assertSentTo([$this->firstApprover], ActionRequiredNotification::class);
    }
}
