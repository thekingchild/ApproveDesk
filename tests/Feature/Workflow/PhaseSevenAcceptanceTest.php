<?php

namespace Tests\Feature\Workflow;

use App\Actions\WorkflowTemplates\CreateWorkflowTemplateAction;
use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Services\WorkflowEngine;
use App\Services\WorkflowTemplates\WorkflowGraphSimulator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PhaseSevenAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_conditional_expense_workflow_keeps_version_one_in_flight_after_a_later_publish(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $manager = User::factory()->create();
        $director = User::factory()->create();

        $template = app(CreateWorkflowTemplateAction::class)->execute(new WorkflowTemplateData(
            name: 'Expense approval',
            description: 'Route high amounts to a director.',
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
                new WorkflowTemplateStepData('Manager review', StepType::Sequential, null, AssigneeType::User, [$manager->id], true),
                new WorkflowTemplateStepData('Director review', StepType::Sequential, null, AssigneeType::User, [$director->id], true),
            ],
            changeSummary: 'Initial expense route.',
        ), $admin);

        $this->assertSame(WorkflowTemplateVersionStatus::Published, $template->publishedVersion?->status);

        $trace = app(WorkflowGraphSimulator::class)->trace(
            $template->publishedVersion->steps,
            ['metadata' => ['amount' => 15000]],
        );
        $this->assertSame('Director review', $trace[2]['name']);
        $this->assertDatabaseCount('workflow_actions', 0);

        $versionOne = $template->publishedVersion?->id;
        $inFlight = app(WorkflowEngine::class)->initialize(Submission::factory()->create([
            'metadata' => ['amount' => 15000],
        ]));
        $this->assertSame($versionOne, $inFlight->template_version_id);
        $this->assertSame('Director review', $inFlight->currentStep?->name);

        $builder = Livewire::actingAs($admin)->test('pages::admin.workflow-builder', ['template' => $template->id]);
        Livewire::actingAs($admin)
            ->test('admin.workflow-builder.simulation', ['templateId' => $template->id, 'steps' => $builder->get('steps')])
            ->call('simulate')
            ->assertSee('data-test="simulation-trace"', false);

        $builder
            ->call('addNode', 'approval')
            ->set('steps.3.assigneeIds', [$manager->id])
            ->set('steps.3.name', 'Finance check')
            ->set('changeSummary', 'Add finance after director.')
            ->call('requestPublish')
            ->call('confirmPublish')
            ->assertHasNoErrors();

        $template->refresh();
        $this->assertSame(2, $template->publishedVersion?->version);
        $this->assertSame($versionOne, $inFlight->fresh()->template_version_id);
        $this->assertSame('Director review', $inFlight->fresh()->currentStep?->name);

        app(WorkflowEngine::class)->processAction($inFlight->fresh(), $director, WorkflowActionType::Approve);
        $this->assertSame(WorkflowStatus::Completed, $inFlight->fresh()->status);
        $this->assertSame($versionOne, $inFlight->fresh()->template_version_id);

        $next = app(WorkflowEngine::class)->initialize(Submission::factory()->create([
            'metadata' => ['amount' => 15000],
        ]));
        $this->assertSame($template->fresh()->publishedVersion?->id, $next->template_version_id);
        $this->assertNotSame($versionOne, $next->template_version_id);
    }
}
