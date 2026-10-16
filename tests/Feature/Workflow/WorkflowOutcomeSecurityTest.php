<?php

namespace Tests\Feature\Workflow;

use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\StepOutcomes;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RV-SEC-07 (builder publish authorization), RV-SEC-11 (outcome key injection),
 * RV-ENG-08 (unknown decisions recorded as Approve).
 */
class WorkflowOutcomeSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_builder_cannot_publish_without_the_publish_permission(): void
    {
        $role = Role::create(['name' => 'workflow-editor', 'guard_name' => 'web']);
        $role->givePermissionTo(['workflows.view', 'workflows.manage']);
        $editor = User::factory()->create()->assignRole($role);
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $published = $template->publishedVersion?->version;

        Livewire::actingAs($editor)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->call('requestPublish')
            ->assertForbidden();

        Livewire::actingAs($editor)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->call('confirmPublish')
            ->assertForbidden();

        $this->assertSame($published, $template->fresh()->publishedVersion?->version);
    }

    public function test_outcome_keys_that_could_run_script_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        StepOutcomes::assertValid([
            ['key' => "x');alert(document.domain);('", 'label' => 'Bad', 'behavior' => 'approve'],
        ]);
    }

    public function test_valid_outcome_keys_are_accepted(): void
    {
        StepOutcomes::assertValid([
            ['key' => 'approve_budget_2', 'label' => 'Approve budget', 'behavior' => 'approve'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_builder_save_reports_an_invalid_outcome_key(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->set('outcomes', [['key' => "x');alert(1);('", 'label' => 'Bad', 'behavior' => 'approve', 'requires_comment' => false]])
            ->call('saveDraft')
            ->assertHasErrors('outcomes');
    }

    public function test_stored_unsafe_outcome_keys_are_never_offered(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $version = $template->publishedVersion;
        $version->forceFill(['outcomes' => [
            ['key' => "x');alert(1);('", 'label' => 'Bad', 'behavior' => 'approve'],
            ['key' => 'escalate_to_cfo', 'label' => 'Send to CFO', 'behavior' => 'escalate'],
        ]])->saveQuietly();

        $keys = array_column(app(StepOutcomes::class)->forVersion($version->fresh()), 'key');

        $this->assertContains('escalate_to_cfo', $keys);
        $this->assertNotContains("x');alert(1);('", $keys);
    }

    public function test_unknown_decision_is_refused_instead_of_approving(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();
        app(WorkflowEngine::class)->initialize($submission);

        Livewire::actingAs($approver)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->call('act', 'anything');

        $this->assertSame(0, WorkflowAction::query()->where('action', 'approve')->count());
    }
}
