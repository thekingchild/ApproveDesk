<?php

namespace Tests\Feature\Approvals;

use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApproverInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $firstApprover;

    private User $secondApprover;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->firstApprover = User::factory()->create()->assignRole('approver');
        $this->secondApprover = User::factory()->create()->assignRole('approver');

        WorkflowTemplate::factory()
            ->default()
            ->sequential([$this->firstApprover, $this->secondApprover])
            ->create();

        $this->submission = Submission::factory()->for($this->submitter, 'submitter')->create();

        app(WorkflowEngine::class)->initialize($this->submission);
    }

    public function test_inbox_lists_pending_item_for_current_step_assignee(): void
    {
        Livewire::actingAs($this->firstApprover)
            ->test('pages::approvals.inbox')
            ->assertSee($this->submission->title)
            ->assertSee('To Action');
    }

    public function test_approve_through_the_page_advances_the_chain(): void
    {
        Livewire::actingAs($this->firstApprover)
            ->test('pages::submissions.show', ['submission' => $this->submission])
            ->assertSee('Your decision')
            ->call('act', 'approve')
            ->assertHasNoErrors();

        $instance = $this->submission->workflowInstance()->first();

        $this->assertSame(2, $instance->currentStep->step_order);
        $this->assertSame(WorkflowStatus::Active, $instance->status);

        // The item moved columns: waiting on the second approver now.
        Livewire::actingAs($this->secondApprover)
            ->test('pages::approvals.inbox')
            ->assertSee($this->submission->title);
    }

    public function test_reject_requires_a_comment(): void
    {
        Livewire::actingAs($this->firstApprover)
            ->test('pages::submissions.show', ['submission' => $this->submission])
            ->call('act', 'reject')
            ->assertHasErrors(['actionComment']);

        $this->assertSame(WorkflowStatus::Active, $this->submission->workflowInstance()->first()->status);
    }

    public function test_reject_with_comment_terminates(): void
    {
        Livewire::actingAs($this->firstApprover)
            ->test('pages::submissions.show', ['submission' => $this->submission])
            ->set('actionComment', 'Missing supporting documents.')
            ->call('act', 'reject')
            ->assertHasNoErrors();

        $this->assertSame(SubmissionStatus::Rejected, $this->submission->fresh()->status);
        $this->assertSame(WorkflowStatus::Rejected, $this->submission->workflowInstance()->first()->status);
    }

    public function test_non_assignee_has_no_decision_panel(): void
    {
        Livewire::actingAs($this->secondApprover)
            ->test('pages::submissions.show', ['submission' => $this->submission])
            ->assertDontSee('Your decision');
    }

    public function test_return_then_resubmit_resumes_at_the_same_step(): void
    {
        $engine = app(WorkflowEngine::class);

        $engine->processAction(
            $this->submission->workflowInstance()->first(),
            $this->firstApprover,
            WorkflowActionType::Return,
            'Please extend section 2.',
        );

        $this->assertSame(SubmissionStatus::Returned, $this->submission->fresh()->status);

        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.show', ['submission' => $this->submission->fresh()])
            ->assertSee('Resubmit for approval')
            ->call('resubmit')
            ->assertHasNoErrors();

        $instance = $this->submission->workflowInstance()->first();

        $this->assertSame(WorkflowStatus::Active, $instance->status);
        $this->assertSame(1, $instance->currentStep->step_order, 'chain must resume where it paused');
    }
}
