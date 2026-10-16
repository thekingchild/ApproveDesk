<?php

namespace Tests\Feature\Workflow;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\RequestTypes\StoreSubmissionFieldValuesAction;
use App\Actions\WorkflowTemplates\SaveWorkflowSelectionRulesAction;
use App\Enums\RequestFieldType;
use App\Enums\StepType;
use App\Enums\SubmissionPriority;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\RequestTypes\RequestSchemaCompiler;
use App\Services\WorkflowEngine;
use App\Services\WorkflowTemplates\WorkflowGraphValidator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-BUG-08 (branches follow their step), RV-BUG-09 (lists survive a resave),
 * RV-BUG-10 (invalid conditions can't publish), RV-BUG-11 (metadata.KEY reads
 * the form field) and RV-BUG-13 (rules on multi-select fields).
 */
class ConditionalRoutingRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    public function test_moving_a_step_in_the_builder_keeps_the_branch_on_that_step(): void
    {
        $template = $this->routeXyz();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->call('moveStep', 2, 1)
            ->call('requestPublish')
            ->call('confirmPublish')
            ->assertHasNoErrors();

        $steps = $template->fresh()->publishedVersion->steps->sortBy('step_order');
        $this->assertSame(['Route', 'X', 'Z', 'Y'], $steps->pluck('name')->values()->all());
        $this->assertSame('Y', $this->firstStepFor($template, SubmissionPriority::Urgent));
        $this->assertSame('X', $this->firstStepFor($template, SubmissionPriority::Normal));
    }

    public function test_moving_a_step_on_the_templates_page_keeps_the_branch_on_that_step(): void
    {
        $template = $this->routeXyz();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-templates')
            ->call('editTemplate', $template->id)
            ->call('moveStep', 2, 1)
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame('Y', $this->firstStepFor($template, SubmissionPriority::Urgent));
    }

    public function test_deleting_a_branch_target_asks_for_a_new_target_instead_of_ending_the_workflow(): void
    {
        $template = $this->routeXyz();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->call('selectStep', 2)
            ->call('deleteSelected')
            ->call('saveDraft')
            ->assertHasErrors('steps.0.trueStep')
            ->assertSee('This branch pointed at a step that was removed');

        $this->assertNull($template->fresh()->draftVersion?->steps->firstWhere('step_order', 1)?->conditions['true_step'] ?? null);
    }

    public function test_duplicating_a_step_does_not_move_existing_branches(): void
    {
        $template = $this->routeXyz();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->call('selectStep', 1)
            ->call('duplicateSelected')
            ->call('requestPublish')
            ->call('confirmPublish')
            ->assertHasNoErrors();

        $route = $template->fresh()->publishedVersion->steps->firstWhere('step_order', 1);
        $this->assertSame(4, $route->conditions['true_step']);
        $this->assertSame(2, $route->conditions['false_step']);
    }

    public function test_builder_resave_keeps_in_lists_and_extra_rules(): void
    {
        [$urgent, $normal] = User::factory()->count(2)->create()->all();
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Route', 'step_type' => StepType::Conditional, 'assignee_ids' => [],
            'conditions' => ['match' => 'any', 'rules' => [
                ['field' => 'priority', 'operator' => 'in', 'value' => ['high', 'urgent']],
                ['field' => 'title', 'operator' => 'contains', 'value' => 'board'],
            ], 'true_step' => 2, 'false_step' => 3],
        ]);
        WorkflowTemplateStep::factory()->forTemplate($template, 2)->create(['name' => 'Urgent', 'assignee_ids' => [$urgent->id]]);
        WorkflowTemplateStep::factory()->forTemplate($template, 3)->create(['name' => 'Normal', 'assignee_ids' => [$normal->id]]);

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->set('steps.2.name', 'Normal review')
            ->call('requestPublish')
            ->call('confirmPublish')
            ->assertHasNoErrors();

        $conditions = $template->fresh()->publishedVersion->steps->firstWhere('step_order', 1)->conditions;
        $this->assertSame('any', $conditions['match']);
        $this->assertSame(['high', 'urgent'], $conditions['rules'][0]['value']);
        $this->assertSame('board', $conditions['rules'][1]['value']);
        $this->assertSame('Urgent', $this->firstStepFor($template, SubmissionPriority::Urgent));
    }

    public function test_versions_damaged_by_the_old_resave_still_route_by_the_list(): void
    {
        [$urgent, $normal] = User::factory()->count(2)->create()->all();
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Route', 'step_type' => StepType::Conditional, 'assignee_ids' => [],
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'priority', 'operator' => 'in', 'value' => 'high, urgent']], 'true_step' => 2, 'false_step' => 3],
        ]);
        WorkflowTemplateStep::factory()->forTemplate($template, 2)->create(['name' => 'Urgent', 'assignee_ids' => [$urgent->id]]);
        WorkflowTemplateStep::factory()->forTemplate($template, 3)->create(['name' => 'Normal', 'assignee_ids' => [$normal->id]]);

        $this->assertSame('Urgent', $this->firstStepFor($template, SubmissionPriority::Urgent));
        $this->assertSame('Normal', $this->firstStepFor($template, SubmissionPriority::Low));
    }

    public function test_condition_on_an_unknown_field_cannot_be_published(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Conditional, 'assignee_ids' => [],
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'amount', 'operator' => 'equals', 'value' => '5']], 'true_step' => 2, 'false_step' => null],
        ]);
        WorkflowTemplateStep::factory()->forTemplate($template, 2)->create(['assignee_ids' => [$approver->id]]);

        try {
            app(WorkflowGraphValidator::class)->validate($template->fresh()->publishedVersion->steps);
            $this->fail('An unknown condition field was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('steps.0.conditionField', $exception->errors());
        }

        Livewire::actingAs($this->admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->call('saveDraft')
            ->assertHasErrors('steps.0.conditionField')
            ->assertSee('This workflow cannot be saved yet');
    }

    public function test_selection_rule_on_an_unknown_field_is_refused(): void
    {
        $type = RequestType::factory()->create();
        $template = WorkflowTemplate::factory()->sequential([User::factory()->create()])->create();

        $this->expectException(ValidationException::class);
        app(SaveWorkflowSelectionRulesAction::class)->execute($type, [[
            'name' => 'Big spend', 'priority' => 1, 'workflow_template_id' => $template->id,
            'field' => 'amount', 'operator' => 'gte', 'value' => '10000',
        ]], $this->admin);
    }

    public function test_metadata_selection_rule_routes_live_requests_by_the_form_field(): void
    {
        $cheap = WorkflowTemplate::factory()->sequential([User::factory()->create()])->create(['name' => 'Cheap']);
        $expensive = WorkflowTemplate::factory()->sequential([User::factory()->create()])->create(['name' => 'Expensive']);
        $type = RequestType::factory()->create(['workflow_template_id' => $cheap->id]);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create(['key' => 'amount', 'label' => 'Amount', 'type' => RequestFieldType::Number]);
        app(PublishRequestTypeVersionAction::class)->execute($version);
        app(SaveWorkflowSelectionRulesAction::class)->execute($type, [[
            'name' => 'Big spend', 'priority' => 1, 'workflow_template_id' => (string) $expensive->id,
            'is_active' => true, 'field' => 'metadata.amount', 'operator' => 'gte', 'value' => '10000',
        ]], $this->admin);

        $this->assertSame($expensive->id, $this->instanceFor($version, 50000)->template_id);
        $this->assertSame($cheap->id, $this->instanceFor($version, 500)->template_id);
    }

    public function test_metadata_condition_step_reads_the_form_field(): void
    {
        [$small, $large] = User::factory()->count(2)->create()->all();
        $template = WorkflowTemplate::factory()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Route', 'step_type' => StepType::Conditional, 'assignee_ids' => [],
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'metadata.amount', 'operator' => 'gte', 'value' => 10000]], 'true_step' => 3, 'false_step' => 2],
        ]);
        WorkflowTemplateStep::factory()->forTemplate($template, 2)->create(['name' => 'Small', 'assignee_ids' => [$small->id]]);
        WorkflowTemplateStep::factory()->forTemplate($template, 3)->create(['name' => 'Large', 'assignee_ids' => [$large->id]]);
        $type = RequestType::factory()->create(['workflow_template_id' => $template->id]);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create(['key' => 'amount', 'label' => 'Amount', 'type' => RequestFieldType::Number]);
        app(PublishRequestTypeVersionAction::class)->execute($version);

        $this->assertSame('Large', $this->instanceFor($version, 25000)->currentStep->name);
        $this->assertSame('Small', $this->instanceFor($version, 25)->currentStep->name);
    }

    public function test_form_rules_on_a_multi_select_field_check_its_items(): void
    {
        $type = RequestType::factory()->create();
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create(['key' => 'tags', 'type' => RequestFieldType::MultiSelect, 'position' => 0, 'configuration' => ['options' => ['A', 'B', 'C']]]);
        $detail = RequestFieldDefinition::factory()->for($version)->create(['key' => 'detail', 'type' => RequestFieldType::ShortText, 'position' => 1, 'configuration' => ['visibility' => ['field' => 'tags', 'operator' => 'equals', 'value' => 'A']]]);
        $other = RequestFieldDefinition::factory()->for($version)->create(['key' => 'other', 'type' => RequestFieldType::ShortText, 'position' => 2, 'configuration' => ['visibility' => ['field' => 'tags', 'operator' => 'in', 'value' => ['B', 'C']]]]);
        $compiler = app(RequestSchemaCompiler::class);

        $this->assertTrue($compiler->isVisible($detail, ['tags' => ['B', 'A']]));
        $this->assertFalse($compiler->isVisible($detail, ['tags' => ['B']]));
        $this->assertFalse($compiler->isVisible($detail, ['tags' => []]));
        $this->assertTrue($compiler->isVisible($other, ['tags' => ['A', 'C']]));
        $this->assertFalse($compiler->isVisible($other, ['tags' => ['A']]));
        $this->assertTrue($compiler->isVisible($detail, ['tags' => 'A']));
    }

    /** Route (urgent → Y, otherwise → X), X, Y, Z. */
    private function routeXyz(): WorkflowTemplate
    {
        [$x, $y, $z] = User::factory()->count(3)->create()->all();
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Route', 'step_type' => StepType::Conditional, 'assignee_ids' => [],
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'urgent']], 'true_step' => 3, 'false_step' => 2],
        ]);
        WorkflowTemplateStep::factory()->forTemplate($template, 2)->create(['name' => 'X', 'assignee_ids' => [$x->id]]);
        WorkflowTemplateStep::factory()->forTemplate($template, 3)->create(['name' => 'Y', 'assignee_ids' => [$y->id]]);
        WorkflowTemplateStep::factory()->forTemplate($template, 4)->create(['name' => 'Z', 'assignee_ids' => [$z->id]]);

        return $template;
    }

    private function firstStepFor(WorkflowTemplate $template, SubmissionPriority $priority): string
    {
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->priority($priority)->create());

        return $instance->fresh()->currentStep->name;
    }

    private function instanceFor(RequestTypeVersion $version, int $amount): WorkflowInstance
    {
        $submission = Submission::factory()->create(['request_type_version_id' => $version->id, 'status' => 'draft']);
        app(StoreSubmissionFieldValuesAction::class)->execute($submission, ['amount' => $amount]);

        return app(WorkflowEngine::class)->initialize($submission->fresh())->fresh(['currentStep']);
    }
}
