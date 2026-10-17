<?php

namespace Tests\Feature\Workflow;

use App\DTOs\WorkflowActionData;
use App\Enums\AssigneeType;
use App\Enums\SubmissionStatus;
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

/**
 * RV-DOC-02: assignees resolve live, so resuming a returned request makes the
 * same "someone can act" check that starting and advancing make.
 */
class ResumeAssigneeCheckTest extends TestCase
{
    use RefreshDatabase;

    private function returnedRequest(User $submitter, WorkflowTemplateStep $step, User $approver): Submission
    {
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        $instance = app(WorkflowEngine::class)->initialize($submission);
        app(WorkflowEngine::class)->processAction($instance, new WorkflowActionData($approver, WorkflowActionType::Return, 'Please fix the totals.', null, $step->id));
        $this->assertSame(WorkflowStatus::OnHold, $instance->fresh()->status);

        return $submission->fresh();
    }

    public function test_resume_refuses_when_the_managers_step_has_nobody_left(): void
    {
        $manager = User::factory()->create();
        $submitter = User::factory()->create(['manager_id' => $manager->id]);
        $template = WorkflowTemplate::factory()->default()->create();
        $step = WorkflowTemplateStep::factory()->forTemplate($template, 1)->create(['assignee_type' => AssigneeType::RequesterManager, 'assignee_ids' => []]);
        $submission = $this->returnedRequest($submitter, $step, $manager);

        // The manager leaves while the request is with the requester.
        $manager->update(['is_active' => false]);

        try {
            app(WorkflowEngine::class)->resume($submission->workflowInstance);
            $this->fail('resumed onto a step nobody can act on');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString($step->name, $exception->getMessage());
        }

        $this->assertSame(WorkflowStatus::OnHold, $submission->workflowInstance->fresh()->status);
        $this->assertSame(SubmissionStatus::Returned, $submission->fresh()->status);
    }

    public function test_resume_follows_the_current_manager(): void
    {
        $manager = User::factory()->create();
        $newManager = User::factory()->create();
        $submitter = User::factory()->create(['manager_id' => $manager->id]);
        $template = WorkflowTemplate::factory()->default()->create();
        $step = WorkflowTemplateStep::factory()->forTemplate($template, 1)->create(['assignee_type' => AssigneeType::RequesterManager, 'assignee_ids' => []]);
        $submission = $this->returnedRequest($submitter, $step, $manager);

        $submitter->update(['manager_id' => $newManager->id]);
        app(WorkflowEngine::class)->resume($submission->workflowInstance);
        app(WorkflowEngine::class)->processAction($submission->workflowInstance->fresh(), new WorkflowActionData($newManager, WorkflowActionType::Approve));

        $this->assertSame(WorkflowStatus::Completed, $submission->workflowInstance->fresh()->status);
    }

    public function test_resume_refuses_when_only_the_requester_would_approve(): void
    {
        $approver = User::factory()->create();
        $submitter = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        $step = WorkflowTemplateStep::factory()->forTemplate($template, 1)->create(['assignee_type' => AssigneeType::User, 'assignee_ids' => [$approver->id]]);
        $submission = $this->returnedRequest($submitter, $step, $approver);

        $step->updateQuietly(['assignee_ids' => [$submitter->id]]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage(WorkflowException::onlyApproverIsRequester($step->name)->getMessage());
        app(WorkflowEngine::class)->resume($submission->workflowInstance);
    }
}
