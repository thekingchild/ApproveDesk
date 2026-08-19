<?php

namespace Tests\Feature\Workflow;

use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowTemplateVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_templates_publish_version_one_with_a_schema_hash(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->sequential([$approver])->create();

        $this->assertNotNull($template->publishedVersion);
        $this->assertSame(1, $template->publishedVersion->version);
        $this->assertSame(WorkflowTemplateVersionStatus::Published, $template->publishedVersion->status);
        $this->assertNotNull($template->publishedVersion->schema_hash);
        $this->assertSame(64, strlen((string) $template->publishedVersion->schema_hash));
        $this->assertSame($template->publishedVersion->id, $template->steps->first()->template_version_id);
    }

    public function test_initialize_binds_the_published_version_and_keeps_it_after_a_later_publish(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $firstVersion = $template->publishedVersion;
        $submission = Submission::factory()->create();
        $instance = app(WorkflowEngine::class)->initialize($submission);

        $this->assertSame($firstVersion?->id, $instance->template_version_id);
        $this->assertSame($firstVersion?->steps->first()->id, $instance->current_step_id);

        Livewire::actingAs($admin)
            ->test('pages::admin.workflow-templates')
            ->call('editTemplate', $template->id)
            ->set('steps.0.name', 'Director review')
            ->set('changeSummary', 'Rename the first step.')
            ->call('publish')
            ->assertHasNoErrors();

        $template->refresh();
        $this->assertSame(WorkflowTemplateVersionStatus::Retired, $firstVersion?->fresh()->status);
        $this->assertSame(2, $template->publishedVersion?->version);
        $this->assertSame('Director review', $template->publishedVersion?->steps->first()->name);
        $this->assertSame($firstVersion?->id, $instance->fresh()->template_version_id);
        $this->assertSame('Approval Level 1', $instance->fresh()->currentStep?->name);

        $next = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        $this->assertSame($template->publishedVersion?->id, $next->template_version_id);
        $this->assertSame('Director review', $next->currentStep?->name);
    }

    public function test_published_steps_cannot_be_mutated(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->sequential([$approver])->create();
        $step = $template->steps->first();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $step->update(['name' => 'Tampered']);
    }

    public function test_new_template_ui_creates_a_published_version(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.workflow-templates')
            ->call('createTemplate')
            ->set('name', 'Versioned chain')
            ->set('isDefault', true)
            ->set('steps.0.assigneeIds', [$approver->id])
            ->call('save')
            ->assertHasNoErrors();

        $template = WorkflowTemplate::query()->where('name', 'Versioned chain')->firstOrFail();
        $this->assertSame(WorkflowTemplateVersionStatus::Published, $template->publishedVersion?->status);
        $this->assertSame(1, $template->publishedVersion?->version);
        $this->assertNotNull($template->publishedVersion?->schema_hash);
    }
}
