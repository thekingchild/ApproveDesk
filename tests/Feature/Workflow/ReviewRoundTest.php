<?php

namespace Tests\Feature\Workflow;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\DTOs\WorkflowActionData;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\ApproverWorkbench;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-ENG-04: a return for revision starts a new review round on that step, so
 * approvals given before the revision no longer complete it.
 */
class ReviewRoundTest extends TestCase
{
    use RefreshDatabase;

    private User $first;

    private User $second;

    private Submission $submission;

    private WorkflowInstance $instance;

    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->engine = app(WorkflowEngine::class);
        $this->first = User::factory()->create()->assignRole('approver');
        $this->second = User::factory()->create()->assignRole('approver');
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Parallel,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => [$this->first->id, $this->second->id],
        ]);
        $this->submission = Submission::factory()->create();
        $this->instance = $this->engine->initialize($this->submission);

        $this->engine->processAction($this->instance, new WorkflowActionData($this->first, WorkflowActionType::Approve));
        $this->engine->processAction($this->instance, new WorkflowActionData($this->second, WorkflowActionType::Return, 'Fix the amount.'));
        app(SubmitSubmissionAction::class)->execute($this->submission->fresh());
    }

    public function test_approvals_before_a_return_do_not_complete_the_revised_request(): void
    {
        $this->engine->processAction($this->instance, new WorkflowActionData($this->second, WorkflowActionType::Approve));

        $this->assertSame(WorkflowStatus::Active, $this->instance->fresh()->status);

        // The earlier approver reviews the revision and is not treated as a duplicate.
        $this->engine->processAction($this->instance, new WorkflowActionData($this->first, WorkflowActionType::Approve));

        $this->assertSame(WorkflowStatus::Completed, $this->instance->fresh()->status);
        $this->assertSame(3, WorkflowAction::query()->where('action', 'approve')->count(), 'History keeps the first-round approval.');
    }

    public function test_earlier_approver_gets_the_decision_back_on_the_request_page_and_in_the_queue(): void
    {
        Livewire::actingAs($this->first)
            ->test('pages::submissions.show', ['submission' => $this->submission->fresh()])
            ->assertSet('canAct', true);

        $item = collect(app(ApproverWorkbench::class)->itemsFor($this->first))
            ->first(fn (array $item): bool => $item['instance']->id === $this->instance->id);

        $this->assertNotNull($item);
        $this->assertTrue($item['needs_me']);
    }
}
