<?php

namespace Tests\Feature\Workflow;

use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Delegation;
use App\Models\Department;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\Workflow\AssignedStepConstraint;
use App\Services\WorkflowEngine;
use App\Support\SeparationOfDuties;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RV-ENG-01: requesters cannot approve, decide, or be delegated their own requests.
 */
class SeparationOfDutiesTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->engine = app(WorkflowEngine::class);
    }

    public function test_it_is_on_by_default(): void
    {
        $this->assertTrue(SeparationOfDuties::enabled());
    }

    public function test_requester_named_on_a_step_cannot_approve(): void
    {
        $submitter = User::factory()->create();
        $other = User::factory()->create();
        $this->stepFor(AssigneeType::User, [$submitter->id, $other->id], StepType::Parallel);
        $instance = $this->engine->initialize(Submission::factory()->create(['submitter_id' => $submitter->id]));

        try {
            $this->engine->processAction($instance->fresh(), $submitter, WorkflowActionType::Approve);
            $this->fail('Self-approval was accepted.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('You submitted this request', $exception->getMessage());
        }

        // The requester's seat does not count: the other approver alone completes the parallel step.
        $this->engine->processAction($instance->fresh(), $other, WorkflowActionType::Approve);
        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);
    }

    public function test_requester_in_a_role_step_is_excluded_from_the_assignees(): void
    {
        $role = Role::findByName('approver');
        $submitter = User::factory()->create()->assignRole($role);
        $colleague = User::factory()->create()->assignRole($role);
        $this->stepFor(AssigneeType::Role, [$role->id]);
        $instance = $this->engine->initialize(Submission::factory()->create(['submitter_id' => $submitter->id]));

        $assignees = $instance->fresh()->currentStep->assignedUsers($instance->submission);

        $this->assertFalse($assignees->contains($submitter));
        $this->assertTrue($assignees->contains($colleague));
    }

    public function test_department_manager_cannot_route_their_own_request_to_themselves(): void
    {
        $manager = User::factory()->create();
        $department = Department::query()->create(['name' => 'Operations', 'manager_id' => $manager->id]);
        $manager->update(['department_id' => $department->id]);
        $this->stepFor(AssigneeType::DepartmentManager, []);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('would be approved only by the requester');

        $this->engine->initialize(Submission::factory()->create([
            'submitter_id' => $manager->id,
            'department_id' => $department->id,
        ]));
    }

    public function test_approver_cannot_delegate_to_the_requester(): void
    {
        $approver = User::factory()->create();
        $submitter = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = $this->engine->initialize(Submission::factory()->create(['submitter_id' => $submitter->id]));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('the requester cannot act on their own request');

        $this->engine->delegate($instance, $approver, $submitter, now()->addDay());
    }

    public function test_delegation_granted_before_the_rule_was_enabled_cannot_be_used(): void
    {
        $approver = User::factory()->create();
        $submitter = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = $this->engine->initialize(Submission::factory()->create(['submitter_id' => $submitter->id]));

        Setting::put(SeparationOfDuties::SETTING, '0');
        $this->engine->delegate($instance, $approver, $submitter, now()->addDay());
        $this->assertSame(1, Delegation::query()->count());
        Setting::put(SeparationOfDuties::SETTING, '1');

        $this->expectException(WorkflowException::class);
        $this->engine->processAction($instance->fresh(), $submitter, WorkflowActionType::Approve);
    }

    public function test_switching_the_rule_off_restores_self_approval(): void
    {
        Setting::put(SeparationOfDuties::SETTING, '0');
        $submitter = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$submitter])->create();
        $instance = $this->engine->initialize(Submission::factory()->create(['submitter_id' => $submitter->id]));

        $this->engine->processAction($instance, $submitter, WorkflowActionType::Approve);

        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);
    }

    public function test_own_requests_are_left_out_of_the_assigned_work_query(): void
    {
        $submitter = User::factory()->create();
        $other = User::factory()->create();
        $this->stepFor(AssigneeType::User, [$submitter->id, $other->id], StepType::Parallel);
        $this->engine->initialize(Submission::factory()->create(['submitter_id' => $submitter->id]));

        $mine = app(AssignedStepConstraint::class)->constrainInstances(WorkflowInstance::query(), $submitter)->count();
        $theirs = app(AssignedStepConstraint::class)->constrainInstances(WorkflowInstance::query(), $other)->count();

        $this->assertSame(0, $mine);
        $this->assertSame(1, $theirs);
    }

    public function test_request_page_explains_why_the_requester_cannot_decide(): void
    {
        $submitter = User::factory()->create()->assignRole('approver');
        $other = User::factory()->create();
        $this->stepFor(AssigneeType::User, [$submitter->id, $other->id], StepType::Parallel);
        $submission = Submission::factory()->create(['submitter_id' => $submitter->id]);
        $this->engine->initialize($submission);

        Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->assertSet('canAct', false)
            ->assertSee('You cannot approve your own request');
    }

    public function test_dynamic_approvers_can_act_from_the_request_page(): void
    {
        // RV-BUG-01: requester-manager steps resolved to nobody on the request page.
        $manager = User::factory()->create()->assignRole('approver');
        $submitter = User::factory()->create(['manager_id' => $manager->id]);
        $this->stepFor(AssigneeType::RequesterManager, []);
        $submission = Submission::factory()->create(['submitter_id' => $submitter->id]);
        $this->engine->initialize($submission);

        Livewire::actingAs($manager)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->assertSet('canAct', true)
            ->assertDontSee('You cannot approve your own request');
    }

    public function test_administrator_can_switch_the_rule_off_and_on(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->assertSet('blockSelfApproval', true)
            ->assertSee('Requesters cannot approve their own requests')
            ->set('blockSelfApproval', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(SeparationOfDuties::enabled());

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('blockSelfApproval', true)
            ->call('save');

        $this->assertTrue(SeparationOfDuties::enabled());
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function stepFor(AssigneeType $type, array $ids, StepType $stepType = StepType::Sequential): void
    {
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => $stepType,
            'assignee_type' => $type,
            'assignee_ids' => $ids,
        ]);
    }
}
