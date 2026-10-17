<?php

namespace Tests\Feature\Workflow;

use App\Actions\WorkflowTemplates\CreateWorkflowTemplateAction;
use App\Actions\WorkflowTemplates\SaveAndPublishWorkflowTemplateAction;
use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Livewire\Forms\WorkflowStepsForm;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** ADR 0006 split of the workflow builder and templates page, and the RV-ARC-06/07 fixes that came with it. */
class WorkflowPagesSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->approver = User::factory()->create();
    }

    public function test_pages_render_with_their_child_components(): void
    {
        $template = WorkflowTemplate::factory()->sequential([$this->approver])->create();

        $this->actingAs($this->admin)->get(route('admin.workflow-templates.index'))
            ->assertOk()
            ->assertSee('data-test="create-template"', false)
            ->assertSee('data-test="save-template"', false);

        $this->actingAs($this->admin)->get(route('admin.workflow-templates.builder', $template))
            ->assertOk()
            ->assertSee('data-test="workflow-canvas"', false)
            ->assertSee('data-test="run-simulation"', false)
            ->assertSee('data-test="restore-version-'.$template->publishedVersion->id.'"', false)
            // Keyed per step: without it, selecting another step kept the old step's bindings.
            ->assertSee('wire:key="step-inspector-0"', false);
    }

    public function test_rv_arc_06_the_builder_refuses_a_quorum_the_templates_page_refuses(): void
    {
        $template = $this->usedTemplate();
        $editorSteps = fn ($component) => $component
            ->set('steps.0.stepType', 'quorum')
            ->set('steps.0.quorumCount', 3);

        $editorSteps(Livewire::actingAs($this->admin)->test('admin.workflow-templates.editor')->call('edit', $template->id))
            ->call('save')
            ->assertHasErrors(['steps.0.quorumCount']);

        // The builder used to save this and only refuse it at publish.
        $editorSteps(Livewire::actingAs($this->admin)->test('pages::admin.workflow-builder', ['template' => $template->id]))
            ->call('saveDraft')
            ->assertHasErrors(['steps.0.quorumCount']);

        $this->assertNull($template->fresh()->draftVersion);
    }

    public function test_rv_arc_06_the_builder_refuses_an_escalation_from_the_final_step(): void
    {
        $template = $this->usedTemplate();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->set('steps.0.timeoutHours', 4)
            ->set('steps.0.timeoutAction', 'escalate')
            ->call('saveDraft')
            ->assertHasErrors(['steps.0.timeoutAction']);
    }

    public function test_rv_arc_06_a_default_template_must_be_active_whichever_way_it_is_saved(): void
    {
        $this->expectException(ValidationException::class);

        app(CreateWorkflowTemplateAction::class)->execute(new WorkflowTemplateData(
            name: 'Inactive default',
            description: null,
            submissionTypes: [],
            isDefault: true,
            isActive: false,
            steps: [new WorkflowTemplateStepData('Review', StepType::Sequential, null, AssigneeType::User, [$this->approver->id], true)],
        ), $this->admin);
    }

    public function test_rv_arc_06_a_removed_role_is_refused_on_save(): void
    {
        $role = Role::create(['name' => 'gone-reviewers', 'guard_name' => 'web']);
        $roleId = $role->id;
        $role->delete();

        try {
            app(CreateWorkflowTemplateAction::class)->execute(new WorkflowTemplateData(
                name: 'Ghost role',
                description: null,
                submissionTypes: [],
                isDefault: false,
                isActive: true,
                steps: [new WorkflowTemplateStepData('Review', StepType::Sequential, null, AssigneeType::Role, [$roleId], true)],
            ), $this->admin);
            $this->fail('A removed role was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('steps.0.assigneeIds', $exception->errors());
        }
    }

    public function test_rv_arc_06_both_editors_load_steps_the_same_way(): void
    {
        $template = WorkflowTemplate::factory()->sequential([$this->approver])->create();
        $version = $template->publishedVersion;
        $strip = fn (array $steps): array => array_map(fn (array $step): array => array_diff_key($step, ['key' => true]), $steps);

        $builder = Livewire::actingAs($this->admin)->test('pages::admin.workflow-builder', ['template' => $template->id]);
        $editor = Livewire::actingAs($this->admin)->test('admin.workflow-templates.editor')->call('edit', $template->id);

        $this->assertSame($strip(WorkflowStepsForm::fromVersion($version)), $strip($builder->get('steps')));
        $this->assertSame($strip($builder->get('steps')), $strip($editor->get('steps')));
    }

    public function test_builder_resets_the_completion_rule_when_the_step_type_changes(): void
    {
        $template = WorkflowTemplate::factory()->sequential([$this->approver])->create();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->assertSet('steps.0.completionRule', 'first_response')
            ->set('steps.0.stepType', 'parallel')
            ->assertSet('steps.0.completionRule', 'all')
            ->set('steps.0.stepType', 'sequential')
            ->set('steps.0.completionRule', 'all')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $step = $template->fresh()->publishedVersion->steps->first();
        $this->assertSame(StepType::Sequential, $step->step_type);
        $this->assertSame('all', $step->completion_rule?->value);
    }

    public function test_rv_arc_07_a_refused_publish_on_the_templates_page_saves_nothing(): void
    {
        $template = $this->usedTemplate();

        Livewire::actingAs($this->admin)
            ->test('admin.workflow-templates.editor')
            ->call('edit', $template->id)
            ->call('addStep')
            ->set('steps.1.assigneeIds', [$this->approver->id])
            ->set('steps.0.stepType', 'conditional')
            ->call('publish')
            ->assertHasErrors();

        $template->refresh();
        $this->assertNull($template->draftVersion, 'The draft must not be saved when publishing is refused.');
        $this->assertSame(1, $template->versions()->count());
    }

    public function test_rv_arc_07_a_refused_publish_in_the_builder_saves_nothing(): void
    {
        $template = $this->usedTemplate();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->call('addNode', 'approval')
            ->set('steps.1.assigneeIds', [$this->approver->id])
            ->set('steps.0.stepType', 'conditional')
            ->call('confirmPublish')
            ->assertHasErrors();

        $this->assertNull($template->fresh()->draftVersion);
    }

    public function test_rv_arc_07_publishing_needs_the_publish_permission_in_the_action(): void
    {
        $template = $this->usedTemplate();
        Role::create(['name' => 'workflow-editor', 'guard_name' => 'web'])->givePermissionTo(['workflows.view', 'workflows.manage']);
        $editor = User::factory()->create()->assignRole('workflow-editor');

        $this->expectException(AuthorizationException::class);

        app(SaveAndPublishWorkflowTemplateAction::class)->execute($template, new WorkflowTemplateData(
            name: $template->name,
            description: null,
            submissionTypes: [],
            isDefault: false,
            isActive: true,
            steps: [new WorkflowTemplateStepData('Review', StepType::Sequential, null, AssigneeType::User, [$this->approver->id], true)],
        ), $editor);
    }

    public function test_rv_arc_07_versions_card_only_restores_this_templates_versions(): void
    {
        $template = WorkflowTemplate::factory()->sequential([$this->approver])->create();
        $other = WorkflowTemplate::factory()->sequential([$this->approver])->create();

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->admin)
            ->test('admin.workflow-builder.versions', ['templateId' => $template->id])
            ->call('restoreVersion', $other->publishedVersion->id);
    }

    public function test_the_simulation_card_refuses_a_user_who_cannot_edit_the_template(): void
    {
        $template = WorkflowTemplate::factory()->sequential([$this->approver])->create();
        $viewer = User::factory()->create()->assignRole('submitter');

        Livewire::actingAs($viewer)
            ->test('admin.workflow-builder.simulation', ['templateId' => $template->id, 'steps' => []])
            ->assertForbidden();
    }

    /** @return array<string, array{string, string}> */
    public static function helpers(): array
    {
        return [
            'builder template loader' => ['pages::admin.workflow-builder', 'template'],
            'builder assignee search' => ['pages::admin.workflow-builder', 'assigneeResults'],
            'editor assignee search' => ['admin.workflow-templates.editor', 'assigneeResultsFor'],
        ];
    }

    #[DataProvider('helpers')]
    public function test_template_helpers_are_not_browser_endpoints(string $component, string $method): void
    {
        $template = WorkflowTemplate::factory()->sequential([$this->approver])->create();
        $params = $component === 'pages::admin.workflow-builder' ? ['template' => $template->id] : [];

        $this->expectException(MethodNotFoundException::class);
        Livewire::actingAs($this->admin)->test($component, $params)->call($method, 0);
    }

    /** A template with a request in flight, so a save makes a draft instead of publishing. */
    private function usedTemplate(): WorkflowTemplate
    {
        $template = WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        return $template->fresh();
    }
}
