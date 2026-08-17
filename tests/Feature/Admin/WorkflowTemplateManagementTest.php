<?php

namespace Tests\Feature\Admin;

use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowTemplateManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    public function test_ordinary_users_cannot_manage_workflow_templates(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)
            ->get(route('admin.workflow-templates.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_an_ordered_sequential_template(): void
    {
        $firstApprover = User::factory()->create();
        $secondApprover = User::factory()->create();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('createTemplate')
            ->set('name', 'Executive approvals')
            ->set('description', 'Two approval levels')
            ->set('isDefault', true)
            ->set('steps', [
                [
                    'name' => 'Department review',
                    'stepType' => StepType::Sequential->value,
                    'assigneeType' => AssigneeType::User->value,
                    'assigneeIds' => [$firstApprover->id],
                ],
                [
                    'name' => 'Executive review',
                    'stepType' => StepType::Sequential->value,
                    'assigneeType' => AssigneeType::User->value,
                    'assigneeIds' => [$secondApprover->id],
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $template = WorkflowTemplate::query()->where('name', 'Executive approvals')->firstOrFail();

        $this->assertTrue($template->is_default);
        $this->assertTrue($template->is_active);
        $this->assertSame($this->admin->id, $template->created_by);
        $this->assertSame([1, 2], $template->steps->pluck('step_order')->all());
        $this->assertSame(['Department review', 'Executive review'], $template->steps->pluck('name')->all());
        $this->assertTrue($template->steps->every(fn ($step) => $step->step_type === StepType::Sequential));
    }

    public function test_admin_can_reorder_steps_before_a_template_is_used(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver, $approver])->create();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('editTemplate', $template->id)
            ->call('moveStep', 1, -1)
            ->set('steps.0.name', 'Now first')
            ->set('steps.1.name', 'Now second')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['Now first', 'Now second'],
            $template->fresh()->steps->pluck('name')->all(),
        );
    }

    public function test_steps_can_target_roles_and_departments(): void
    {
        $role = Role::findByName('approver');
        $department = Department::factory()->create();
        User::factory()->create()->assignRole('approver');
        User::factory()->create(['department_id' => $department->id]);

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('createTemplate')
            ->set('name', 'Role and department flow')
            ->set('submissionTypes', ['request'])
            ->set('steps', [
                [
                    'name' => 'Approver review',
                    'stepType' => StepType::Parallel->value,
                    'assigneeType' => AssigneeType::Role->value,
                    'assigneeIds' => [$role->id],
                ],
                [
                    'name' => 'Department review',
                    'stepType' => StepType::Sequential->value,
                    'assigneeType' => AssigneeType::Department->value,
                    'assigneeIds' => [$department->id],
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $steps = WorkflowTemplate::query()
            ->where('name', 'Role and department flow')
            ->firstOrFail()
            ->steps;

        $this->assertSame(StepType::Parallel, $steps[0]->step_type);
        $this->assertSame(AssigneeType::Role, $steps[0]->assignee_type);
        $this->assertSame([$role->id], $steps[0]->assignee_ids);
        $this->assertSame(AssigneeType::Department, $steps[1]->assignee_type);
        $this->assertSame([$department->id], $steps[1]->assignee_ids);
    }

    public function test_admin_can_configure_a_quorum_threshold(): void
    {
        $approvers = User::factory()->count(3)->create();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('createTemplate')
            ->set('name', 'Committee quorum flow')
            ->set('submissionTypes', ['document'])
            ->set('steps.0.stepType', StepType::Quorum->value)
            ->set('steps.0.quorumCount', 2)
            ->set('steps.0.assigneeIds', $approvers->pluck('id')->all())
            ->call('save')
            ->assertHasNoErrors();

        $step = WorkflowTemplate::query()
            ->where('name', 'Committee quorum flow')
            ->firstOrFail()
            ->steps
            ->first();

        $this->assertSame(StepType::Quorum, $step->step_type);
        $this->assertSame(2, $step->quorum_count);
        $this->assertSame($approvers->pluck('id')->all(), $step->assignee_ids);
    }

    public function test_admin_can_disable_delegation_for_a_step(): void
    {
        $approver = User::factory()->create();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('createTemplate')
            ->set('name', 'Non-delegable flow')
            ->set('submissionTypes', ['request'])
            ->set('steps.0.assigneeIds', [$approver->id])
            ->set('steps.0.allowDelegation', false)
            ->call('save')
            ->assertHasNoErrors();

        $step = WorkflowTemplate::query()
            ->where('name', 'Non-delegable flow')
            ->firstOrFail()
            ->steps
            ->firstOrFail();

        $this->assertFalse($step->allow_delegation);
    }

    public function test_admin_can_create_conditional_and_automatic_steps(): void
    {
        $standardApprover = User::factory()->create();
        $executiveApprover = User::factory()->create();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('createTemplate')
            ->set('name', 'Value-based automation')
            ->set('submissionTypes', ['memo'])
            ->set('steps', [
                [
                    'name' => 'Route by amount',
                    'stepType' => StepType::Conditional->value,
                    'quorumCount' => null,
                    'assigneeType' => AssigneeType::User->value,
                    'assigneeIds' => [],
                    'allowDelegation' => false,
                    'conditionField' => 'metadata.amount',
                    'conditionOperator' => 'gte',
                    'conditionValue' => '10000',
                    'trueStep' => 3,
                    'falseStep' => 2,
                ],
                [
                    'name' => 'Standard review',
                    'stepType' => StepType::Sequential->value,
                    'quorumCount' => null,
                    'assigneeType' => AssigneeType::User->value,
                    'assigneeIds' => [$standardApprover->id],
                    'allowDelegation' => true,
                ],
                [
                    'name' => 'Automatic policy check',
                    'stepType' => StepType::Auto->value,
                    'quorumCount' => null,
                    'assigneeType' => AssigneeType::User->value,
                    'assigneeIds' => [],
                    'allowDelegation' => false,
                ],
                [
                    'name' => 'Executive review',
                    'stepType' => StepType::Sequential->value,
                    'quorumCount' => null,
                    'assigneeType' => AssigneeType::User->value,
                    'assigneeIds' => [$executiveApprover->id],
                    'allowDelegation' => true,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $steps = WorkflowTemplate::query()
            ->where('name', 'Value-based automation')
            ->firstOrFail()
            ->steps;

        $this->assertSame(StepType::Conditional, $steps[0]->step_type);
        $this->assertSame('metadata.amount', data_get($steps[0]->conditions, 'rules.0.field'));
        $this->assertSame(3, $steps[0]->conditions['true_step']);
        $this->assertSame(StepType::Auto, $steps[2]->step_type);
        $this->assertSame([], $steps[2]->assignee_ids);
        $this->assertFalse($steps[2]->allow_delegation);
    }

    public function test_quorum_cannot_exceed_active_resolved_assignees(): void
    {
        $approvers = User::factory()->count(2)->create();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('createTemplate')
            ->set('name', 'Impossible committee flow')
            ->set('submissionTypes', ['note'])
            ->set('steps.0.stepType', StepType::Quorum->value)
            ->set('steps.0.quorumCount', 3)
            ->set('steps.0.assigneeIds', $approvers->pluck('id')->all())
            ->call('save')
            ->assertHasErrors(['steps.0.quorumCount']);

        $this->assertDatabaseMissing('workflow_templates', ['name' => 'Impossible committee flow']);
    }

    public function test_used_template_steps_are_immutable_but_metadata_can_change(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();
        app(WorkflowEngine::class)->initialize($submission);

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('editTemplate', $template->id)
            ->set('steps.0.name', 'Rewritten historical step')
            ->call('save')
            ->assertHasErrors(['steps']);

        $this->assertSame('Approval Level 1', $template->fresh()->steps->first()->name);

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('editTemplate', $template->id)
            ->set('name', 'Renamed safely')
            ->set('description', 'Metadata may still change.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed safely', $template->fresh()->name);
        $this->assertSame('Approval Level 1', $template->fresh()->steps->first()->name);
    }

    public function test_active_type_specific_templates_cannot_overlap(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->sequential([$approver])->create([
            'submission_types' => ['memo'],
        ]);

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('createTemplate')
            ->set('name', 'Duplicate memo flow')
            ->set('submissionTypes', ['memo'])
            ->set('steps.0.assigneeIds', [$approver->id])
            ->call('save')
            ->assertHasErrors(['submissionTypes']);

        $this->assertDatabaseMissing('workflow_templates', ['name' => 'Duplicate memo flow']);
    }

    public function test_selecting_a_new_default_replaces_the_previous_default(): void
    {
        $approver = User::factory()->create();
        $oldDefault = WorkflowTemplate::factory()->default()->sequential([$approver])->create();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('createTemplate')
            ->set('name', 'New default')
            ->set('isDefault', true)
            ->set('steps.0.assigneeIds', [$approver->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($oldDefault->fresh()->is_default);
        $this->assertTrue(WorkflowTemplate::query()->where('name', 'New default')->firstOrFail()->is_default);
        $this->assertSame(1, WorkflowTemplate::query()->where('is_default', true)->count());
    }
}
