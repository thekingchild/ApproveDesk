<?php

namespace Tests\Feature\Workflow;

use App\Actions\Submissions\WithdrawSubmissionAction;
use App\DTOs\WorkflowActionData;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-ENG-05: withdrawal goes through the engine — locked, recorded, and
 * refused once the request has been decided.
 */
class WithdrawThroughEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $approver;

    private Submission $submission;

    private WorkflowInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        $this->submission = Submission::factory()->create(['submitter_id' => $this->submitter->id]);
        $this->instance = app(WorkflowEngine::class)->initialize($this->submission);
    }

    public function test_withdrawal_cancels_the_workflow_and_is_recorded_in_the_history(): void
    {
        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.show', ['submission' => $this->submission->fresh()])
            ->call('withdraw');

        $this->assertSame(WorkflowStatus::Cancelled, $this->instance->fresh()->status);
        $this->assertSame(SubmissionStatus::Withdrawn, $this->submission->fresh()->status);

        $entry = WorkflowAction::query()->where('action', WorkflowActionType::Withdraw)->sole();
        $this->assertSame($this->submitter->id, $entry->actor_id);
        $this->assertSame($this->instance->current_step_id, $entry->step_id);
    }

    public function test_returned_request_can_be_withdrawn(): void
    {
        app(WorkflowEngine::class)->processAction($this->instance, new WorkflowActionData($this->approver, WorkflowActionType::Return, 'Please revise.'));

        app(WithdrawSubmissionAction::class)->execute($this->submission->fresh(), $this->submitter);

        $this->assertSame(WorkflowStatus::Cancelled, $this->instance->fresh()->status);
        $this->assertSame(SubmissionStatus::Withdrawn, $this->submission->fresh()->status);
    }

    public function test_a_request_decided_first_cannot_be_withdrawn_afterwards(): void
    {
        $stale = $this->submission->fresh();
        app(WorkflowEngine::class)->processAction($this->instance, new WorkflowActionData($this->approver, WorkflowActionType::Approve));

        try {
            app(WithdrawSubmissionAction::class)->execute($stale, $this->submitter);
            $this->fail('A completed request was withdrawn.');
        } catch (WorkflowException) {
            // expected
        }

        $this->assertSame(WorkflowStatus::Completed, $this->instance->fresh()->status);
        $this->assertSame(SubmissionStatus::Approved, $this->submission->fresh()->status);
        $this->assertSame(0, WorkflowAction::query()->where('action', WorkflowActionType::Withdraw)->count());
    }
}
