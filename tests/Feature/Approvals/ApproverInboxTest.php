<?php

namespace Tests\Feature\Approvals;

use App\Enums\SubmissionPriority;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
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

    private WorkflowTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->firstApprover = User::factory()->create()->assignRole('approver');
        $this->secondApprover = User::factory()->create()->assignRole('approver');

        $this->template = WorkflowTemplate::factory()
            ->default()
            ->sequential([$this->firstApprover, $this->secondApprover])
            ->create();

        $this->submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'title' => 'Quarterly expense approval',
        ]);

        app(WorkflowEngine::class)->initialize($this->submission);
    }

    public function test_inbox_lists_pending_item_for_current_step_assignee(): void
    {
        Livewire::actingAs($this->firstApprover)
            ->test('pages::approvals.inbox')
            ->assertSee($this->submission->title)
            ->assertSee('Needs my decision')
            ->assertSee('Waiting on others')
            ->assertSee('Current step')
            ->assertSee('Last activity');
    }

    public function test_saved_views_search_and_filters_narrow_the_prioritized_queue(): void
    {
        $this->template->steps()->first()->updateQuietly(['timeout_hours' => 24]);
        $this->submission->update([
            'priority' => SubmissionPriority::Urgent,
            'type' => SubmissionType::Memo,
        ]);
        $this->submission->workflowInstance()->update(['step_started_at' => now()->subHours(25)]);

        $other = Submission::factory()->for($this->submitter, 'submitter')->create([
            'title' => 'Routine equipment request',
            'priority' => SubmissionPriority::Normal,
            'type' => SubmissionType::Request,
        ]);
        app(WorkflowEngine::class)->initialize($other);

        Livewire::actingAs($this->firstApprover)
            ->test('pages::approvals.inbox')
            ->call('setView', 'overdue')
            ->assertSee($this->submission->title)
            ->assertDontSee($other->title)
            ->call('setView', 'needs_decision')
            ->set('search', 'equipment')
            ->assertSee($other->title)
            ->assertDontSee($this->submission->title)
            ->set('search', '')
            ->set('priority', 'urgent')
            ->set('type', 'memo')
            ->assertSee($this->submission->title)
            ->assertDontSee($other->title);
    }

    public function test_inline_preview_approves_without_leaving_the_queue(): void
    {
        $instance = $this->submission->workflowInstance()->first();

        Livewire::actingAs($this->firstApprover)
            ->test('pages::approvals.inbox')
            ->call('openPreview', $instance->id)
            ->assertSee('Open full request')
            ->assertSee($this->submission->title)
            ->call('approvePreview')
            ->assertHasNoErrors();

        $this->assertSame(2, $instance->fresh()->currentStep->step_order);
    }

    public function test_guarded_batch_approval_accepts_only_homogeneous_eligible_requests(): void
    {
        $other = Submission::factory()->for($this->submitter, 'submitter')->create(['title' => 'Second eligible request']);
        app(WorkflowEngine::class)->initialize($other);

        $ids = [
            (string) $this->submission->workflowInstance()->value('id'),
            (string) $other->workflowInstance()->value('id'),
        ];

        Livewire::actingAs($this->firstApprover)
            ->test('pages::approvals.inbox')
            ->set('selected', $ids)
            ->assertSet('batchEligible', true)
            ->call('batchApprove')
            ->assertHasNoErrors();

        $this->assertSame(2, $this->submission->workflowInstance()->first()->currentStep->step_order);
        $this->assertSame(2, $other->workflowInstance()->first()->currentStep->step_order);

        Livewire::actingAs($this->firstApprover)
            ->test('pages::approvals.inbox')
            ->set('selected', $ids)
            ->call('batchApprove')
            ->assertHasErrors(['selected']);
    }

    public function test_approve_through_the_page_advances_the_chain(): void
    {
        Livewire::actingAs($this->firstApprover)
            ->test('submissions.decision', ['submission' => $this->submission])
            ->assertSee('Your decision')
            ->assertSee('Shortcuts: [ previous, ] next, A approve, R reject')
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
            ->test('submissions.decision', ['submission' => $this->submission])
            ->call('act', 'reject')
            ->assertHasErrors(['actionComment']);

        $this->assertSame(WorkflowStatus::Active, $this->submission->workflowInstance()->first()->status);
    }

    public function test_reject_with_comment_terminates(): void
    {
        Livewire::actingAs($this->firstApprover)
            ->test('submissions.decision', ['submission' => $this->submission])
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
