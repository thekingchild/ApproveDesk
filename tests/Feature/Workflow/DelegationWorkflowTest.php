<?php

namespace Tests\Feature\Workflow;

use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Delegation;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Notifications\DelegationAssignedNotification;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class DelegationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    private User $approver;

    private User $delegate;

    private WorkflowInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(WorkflowEngine::class);
        $this->approver = User::factory()->create();
        $this->delegate = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        $this->instance = $this->engine->initialize(Submission::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_assignee_can_delegate_and_delegate_can_decide(): void
    {
        Notification::fake();

        $delegation = $this->engine->delegate(
            $this->instance,
            $this->approver,
            $this->delegate,
            now()->addDay(),
            'Cover this approval while I am away.',
        );

        $this->assertSame($this->delegate->id, $delegation->delegate_id);
        $this->assertDatabaseHas('workflow_actions', [
            'instance_id' => $this->instance->id,
            'actor_id' => $this->approver->id,
            'action' => WorkflowActionType::Delegate->value,
            'delegated_to' => $this->delegate->id,
        ]);
        Notification::assertSentTo($this->delegate, DelegationAssignedNotification::class);

        $this->engine->processAction(
            $this->instance,
            $this->delegate,
            WorkflowActionType::Approve,
            'Approved as delegate.',
        );

        $this->assertSame(WorkflowStatus::Completed, $this->instance->fresh()->status);
    }

    public function test_delegator_cannot_decide_while_grant_is_active(): void
    {
        $this->engine->delegate($this->instance, $this->approver, $this->delegate, now()->addHour());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('currently delegated');

        $this->engine->processAction($this->instance, $this->approver, WorkflowActionType::Approve);
    }

    public function test_delegation_modal_grants_delegate_submission_access(): void
    {
        Notification::fake();

        Livewire::actingAs($this->approver)
            ->test('submissions.decision', ['submission' => $this->instance->submission])
            ->assertSee('Delegate')
            ->set('delegateUserId', (string) $this->delegate->id)
            ->set('delegationExpiresAt', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('delegationComment', 'Please cover this decision.')
            ->call('delegateApproval')
            ->assertHasNoErrors();

        Livewire::actingAs($this->delegate)
            ->test('pages::approvals.inbox')
            ->assertSee($this->instance->submission->reference_number);

        Livewire::actingAs($this->delegate)
            ->test('submissions.decision', ['submission' => $this->instance->submission])
            ->assertSee('Your decision')
            ->set('actionComment', 'Approved through delegated authority.')
            ->call('act', WorkflowActionType::Approve->value)
            ->assertHasNoErrors();

        $this->assertSame(WorkflowStatus::Completed, $this->instance->fresh()->status);
    }

    public function test_expired_grant_restores_delegator_and_removes_delegate_access(): void
    {
        Carbon::setTestNow('2026-08-17 09:00:00');
        $this->engine->delegate($this->instance, $this->approver, $this->delegate, now()->addHour());
        Carbon::setTestNow('2026-08-17 10:00:01');

        try {
            $this->engine->processAction($this->instance, $this->delegate, WorkflowActionType::Approve);
            $this->fail('Expired delegate authority must be rejected.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('not an assignee', $exception->getMessage());
        }

        $this->engine->processAction($this->instance, $this->approver, WorkflowActionType::Approve);
        $this->assertSame(WorkflowStatus::Completed, $this->instance->fresh()->status);
    }

    public function test_delegation_rejects_disabled_steps_and_duplicate_seats(): void
    {
        $this->instance->currentStep->updateQuietly(['allow_delegation' => false]);

        try {
            $this->engine->delegate($this->instance, $this->approver, $this->delegate, now()->addHour());
            $this->fail('Disabled delegation must be rejected.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('disables it', $exception->getMessage());
        }

        $this->instance->currentStep->updateQuietly(['allow_delegation' => true]);
        $otherAssigned = User::factory()->create();
        $ids = $this->instance->currentStep->assignee_ids;
        $this->instance->currentStep->updateQuietly(['assignee_ids' => [...$ids, $otherAssigned->id]]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('already holds a seat');
        $this->engine->delegate($this->instance, $this->approver, $otherAssigned, now()->addHour());
    }

    public function test_expired_grants_are_kept_as_evidence_but_stop_being_active(): void
    {
        Carbon::setTestNow('2026-08-17 09:00:00');
        $this->engine->delegate($this->instance, $this->approver, $this->delegate, now()->addHour());

        Delegation::factory()->create([
            'instance_id' => $this->instance->id,
            'step_id' => $this->instance->current_step_id,
            'expires_at' => now()->addDay(),
        ]);

        Carbon::setTestNow('2026-08-17 10:00:01');

        // RV-DATA-05: expired delegations are kept as evidence; they simply stop being active.
        $this->assertSame(2, Delegation::query()->count());
        $this->assertSame(1, Delegation::query()->active()->count());
        $this->assertSame(1, WorkflowAction::query()->where('action', WorkflowActionType::Delegate)->count());
    }
}
