<?php

namespace Tests\Feature\Workflow;

use App\Actions\WorkflowTemplates\CreateWorkflowTemplateAction;
use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use App\Services\WorkflowTemplates\WorkflowGraphSimulator;
use App\Services\WorkflowTemplates\WorkflowGraphValidator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_builder_opens_with_canvas_and_list_views(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->sequential([$approver])->create();

        $this->actingAs($admin)
            ->get(route('admin.workflow-templates.builder', $template))
            ->assertOk()
            ->assertSee('data-test="workflow-canvas"', false)
            ->assertSee('Canvas')
            ->assertSee('List');

        Livewire::actingAs($admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->call('addNode', 'notification')
            ->call('undo')
            ->assertSet('steps.0.stepType', 'sequential')
            ->call('redo')
            ->assertSet('steps.1.stepType', 'notification')
            ->set('viewMode', 'list')
            ->assertSee('data-test="workflow-list"', false)
            ->call('moveStep', 1, -1)
            ->assertSet('steps.0.stepType', 'notification')
            ->call('simulate')
            ->assertSee('data-test="simulation-trace"', false);
    }

    public function test_validator_rejects_unreachable_and_missing_assignees(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->sequential([$approver])->create();
        $steps = $template->publishedVersion->steps;
        $steps[0]->forceFill(['assignee_ids' => []])->syncOriginal();
        $steps[0]->assignee_ids = [];

        $this->expectException(ValidationException::class);
        app(WorkflowGraphValidator::class)->validate($steps);
    }

    public function test_simulation_explains_a_conditional_route_without_writing_actions(): void
    {
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $data = new WorkflowTemplateData(
            name: 'Expense route',
            description: null,
            submissionTypes: [],
            isDefault: true,
            isActive: true,
            steps: [
                new WorkflowTemplateStepData('Amount gate', StepType::Conditional, null, AssigneeType::User, [], false, [
                    'match' => 'all',
                    'rules' => [['field' => 'metadata.amount', 'operator' => 'gte', 'value' => 10000]],
                    'true_step' => 3,
                    'false_step' => 2,
                ]),
                new WorkflowTemplateStepData('Manager review', StepType::Sequential, null, AssigneeType::User, [$approver->id], true),
                new WorkflowTemplateStepData('Director review', StepType::Sequential, null, AssigneeType::User, [$approver->id], true),
            ],
        );
        $template = app(CreateWorkflowTemplateAction::class)->execute($data, $admin);

        $trace = app(WorkflowGraphSimulator::class)->trace(
            $template->publishedVersion->steps,
            ['metadata' => ['amount' => 15000]],
        );

        $this->assertSame('Start', $trace[0]['name']);
        $this->assertStringContainsString('true branch', $trace[1]['detail']);
        $this->assertSame('Director review', $trace[2]['name']);
        $this->assertSame('End', $trace[array_key_last($trace)]['name']);
        $this->assertDatabaseCount('workflow_actions', 0);
    }

    public function test_clone_restore_and_publish_confirmation(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create(['name' => 'Source chain']);

        Livewire::actingAs($admin)
            ->test('pages::admin.workflow-templates')
            ->call('cloneTemplate', $template->id)
            ->assertHasNoErrors();

        $clone = WorkflowTemplate::query()->where('name', 'Source chain copy')->firstOrFail();
        $this->assertNotSame($template->id, $clone->id);
        $this->assertSame(WorkflowTemplateVersionStatus::Published, $clone->publishedVersion?->status);

        Livewire::actingAs($admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->call('addNode', 'approval')
            ->set('steps.1.assigneeIds', [$approver->id])
            ->call('requestPublish')
            ->assertSet('confirmingPublish', true)
            ->call('confirmPublish')
            ->assertHasNoErrors();

        $template->refresh();
        $this->assertSame(2, $template->publishedVersion?->version);
        app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        $firstId = $template->versions()->where('version', 1)->value('id');
        Livewire::actingAs($admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->call('restoreVersion', $firstId)
            ->assertHasNoErrors();

        $this->assertNotNull($template->fresh()->draftVersion);
    }

    public function test_notification_step_advances_automatically(): void
    {
        $admin = User::factory()->create();
        $approver = User::factory()->create();
        $data = new WorkflowTemplateData(
            name: 'Notify then approve',
            description: null,
            submissionTypes: [],
            isDefault: true,
            isActive: true,
            steps: [
                new WorkflowTemplateStepData('Ping finance', StepType::Notification, null, AssigneeType::User, [], false),
                new WorkflowTemplateStepData('Finance review', StepType::Sequential, null, AssigneeType::User, [$approver->id], true),
            ],
        );
        app(CreateWorkflowTemplateAction::class)->execute($data, $admin);

        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        $this->assertSame(WorkflowStatus::Active, $instance->status);
        $this->assertSame('Finance review', $instance->currentStep?->name);
        $this->assertDatabaseHas('workflow_actions', ['comment' => 'Notification step completed automatically.']);
    }
}
