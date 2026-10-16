<?php

namespace Tests\Feature\Workflow;

use App\Enums\GroupCompletion;
use App\Enums\StepType;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-BUG-05: saving on Administration → Workflow templates must not reset
 * builder-only settings (completion rule, custom outcomes).
 */
class TemplatesPageSavePreservesBuilderSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_only_the_description_keeps_all_approvers_and_custom_outcomes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');
        $a = User::factory()->create();
        $b = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Board', 'step_type' => StepType::Sequential, 'completion_rule' => GroupCompletion::All,
            'assignee_ids' => [$a->id, $b->id],
        ]);
        DB::table('workflow_template_versions')->where('id', $template->fresh()->publishedVersion->id)->update(['outcomes' => json_encode([
            ['key' => 'approve_budget', 'label' => 'Approve budget', 'behavior' => 'approve', 'requires_comment' => false],
        ])]);

        Livewire::actingAs($admin)->test('pages::admin.workflow-templates')
            ->call('editTemplate', $template->id)
            ->set('description', 'Clarified wording')
            ->call('save')->assertHasNoErrors();

        $published = $template->fresh()->publishedVersion;
        $this->assertSame(GroupCompletion::All, $published->steps->first()->groupCompletion());
        $this->assertSame('approve_budget', $published->outcomes[0]['key'] ?? null);
        $this->assertSame('Clarified wording', $template->fresh()->description);

        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        app(WorkflowEngine::class)->processAction($instance->fresh(), $a, WorkflowActionType::Approve);
        $this->assertSame(WorkflowStatus::Active, $instance->fresh()->status, 'Both approvers are still required.');
    }

    public function test_changing_the_step_type_applies_that_types_default_rule(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');
        $a = User::factory()->create();
        $b = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Board', 'step_type' => StepType::Sequential, 'completion_rule' => GroupCompletion::All,
            'assignee_ids' => [$a->id, $b->id],
        ]);

        Livewire::actingAs($admin)->test('pages::admin.workflow-templates')
            ->call('editTemplate', $template->id)
            ->set('steps.0.stepType', StepType::Parallel->value)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(GroupCompletion::fromStepType(StepType::Parallel), $template->fresh()->publishedVersion->steps->first()->groupCompletion());
    }
}
