<?php

namespace Tests\Feature\Workflow;

use App\DTOs\DelegationData;
use App\DTOs\WorkflowActionData;
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
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-ENG-02: a decision applies only to the step the actor saw. The same
 * person on two consecutive steps must not complete both with one
 * double-click, retry, or replay.
 */
class StaleStepProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private Submission $submission;

    private WorkflowInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver, $this->approver])->create();
        $this->submission = Submission::factory()->create();
        $this->instance = app(WorkflowEngine::class)->initialize($this->submission);
    }

    public function test_engine_refuses_an_action_for_a_step_that_has_already_moved_on(): void
    {
        $firstStep = (int) $this->instance->current_step_id;
        $engine = app(WorkflowEngine::class);

        $engine->processAction($this->instance, new WorkflowActionData($this->approver, WorkflowActionType::Approve, expectedStepId: $firstStep));

        try {
            $engine->processAction($this->instance, new WorkflowActionData($this->approver, WorkflowActionType::Approve, expectedStepId: $firstStep));
            $this->fail('A replayed approval decided the next step.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('moved on', $exception->getMessage());
        }

        $this->assertSame(WorkflowStatus::Active, $this->instance->fresh()->status);
        $this->assertSame(1, WorkflowAction::query()->where('action', 'approve')->count());
    }

    public function test_two_open_request_pages_cannot_decide_two_steps(): void
    {
        $first = Livewire::actingAs($this->approver)->test('submissions.decision', ['submission' => $this->submission->fresh()]);
        $second = Livewire::actingAs($this->approver)->test('submissions.decision', ['submission' => $this->submission->fresh()]);

        $first->call('act', 'approve');
        $second->call('act', 'approve');

        $this->assertSame(WorkflowStatus::Active, $this->instance->fresh()->status);
        $this->assertSame(1, WorkflowAction::query()->where('action', 'approve')->count());
    }

    public function test_api_refuses_a_stale_step_id(): void
    {
        $firstStep = (int) $this->instance->current_step_id;
        Sanctum::actingAs($this->approver, ['approvals:act']);
        $url = '/api/v1/submissions/'.$this->submission->id.'/actions';

        $this->postJson($url, ['action' => 'approve', 'step_id' => $firstStep])->assertOk();
        $this->postJson($url, ['action' => 'approve', 'step_id' => $firstStep])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $this->assertSame(WorkflowStatus::Active, $this->instance->fresh()->status);
    }

    public function test_delegation_is_refused_for_a_step_that_has_already_moved_on(): void
    {
        $firstStep = (int) $this->instance->current_step_id;
        $engine = app(WorkflowEngine::class);
        $engine->processAction($this->instance, new WorkflowActionData($this->approver, WorkflowActionType::Approve, expectedStepId: $firstStep));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('moved on');

        $engine->delegate($this->instance, new DelegationData($this->approver, User::factory()->create(), now()->addDay(), expectedStepId: $firstStep));
    }
}
