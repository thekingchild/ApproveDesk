<?php

namespace Tests\Feature\Submissions;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Actions\Submissions\WithdrawSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\ApprovalReportService;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Regression tests for RV-BUG-22: the approval report showed a withdrawn step
 * as Completed and listed today's assignees instead of the people who acted.
 */
class ApprovalReportStepStateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $submitter;

    private User $first;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->first = User::factory()->create(['name' => 'Ada First'])->assignRole('approver');
        $this->second = User::factory()->create(['name' => 'Bola Second'])->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->first, $this->second])->create();
    }

    private function submitted(): Submission
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create(['status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);

        return $submission->fresh();
    }

    /** @return list<array{name: string, state: string, actors: string}> */
    private function steps(Submission $submission): array
    {
        return app(ApprovalReportService::class)->build($submission->fresh(), $this->admin)['workflow_steps'];
    }

    public function test_withdrawn_step_is_reported_as_withdrawn_and_later_steps_as_not_reached(): void
    {
        $submission = $this->submitted();
        app(WithdrawSubmissionAction::class)->execute($submission, $this->submitter);

        $steps = $this->steps($submission);

        $this->assertSame('Withdrawn', $steps[0]['state']);
        // Nobody decided the step; the requester's withdrawal is not a decision on it.
        $this->assertSame('', $steps[0]['actors']);
        $this->assertSame('Not reached', $steps[1]['state']);
    }

    public function test_decided_step_names_who_acted_even_after_they_are_deactivated(): void
    {
        $submission = $this->submitted();
        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->firstOrFail(), $this->first, WorkflowActionType::Approve);
        $this->first->forceFill(['is_active' => false])->save();

        $steps = $this->steps($submission);

        $this->assertSame('Completed', $steps[0]['state']);
        $this->assertSame('Ada First', $steps[0]['actors']);
        $this->assertSame('Current', $steps[1]['state']);
        $this->assertSame('Bola Second', $steps[1]['actors']);
    }

    public function test_step_approved_then_withdrawn_later_keeps_its_completed_state_and_actor(): void
    {
        $submission = $this->submitted();
        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->firstOrFail(), $this->first, WorkflowActionType::Approve);
        app(WithdrawSubmissionAction::class)->execute($submission->fresh(), $this->submitter);

        $steps = $this->steps($submission);

        $this->assertSame(['Completed', 'Withdrawn'], array_column($steps, 'state'));
        $this->assertSame('Ada First', $steps[0]['actors']);
    }

    public function test_rejected_step_names_the_rejector(): void
    {
        $submission = $this->submitted();
        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->firstOrFail(), $this->first, WorkflowActionType::Reject, 'Out of budget');

        $steps = $this->steps($submission);

        $this->assertSame(['Rejected', 'Not reached'], array_column($steps, 'state'));
        $this->assertSame('Ada First', $steps[0]['actors']);
    }

    public function test_pdf_report_renders_the_withdrawn_state(): void
    {
        $submission = $this->submitted();
        app(WithdrawSubmissionAction::class)->execute($submission, $this->submitter);

        $this->actingAs($this->admin)
            ->get("/submissions/{$submission->id}/approval-report")
            ->assertOk()
            ->assertSee('Withdrawn')
            ->assertSee('Not reached')
            ->assertSee('Decided by / assigned to');
    }
}
