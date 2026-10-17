<?php

namespace Tests\Feature\Workflow;

use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-BUG-37: a builder opened before another administrator saved or
 * published must not silently write its stale chain over theirs.
 */
class WorkflowBuilderStaleSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $first;

    private User $second;

    private WorkflowTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->first = User::factory()->create()->assignRole('admin');
        $this->second = User::factory()->create()->assignRole('admin');
        $this->template = WorkflowTemplate::factory()->default()->sequential([User::factory()->create(), User::factory()->create()])->create();
        // An instance keeps edits as drafts until someone publishes.
        app(WorkflowEngine::class)->initialize(Submission::factory()->create());
    }

    public function test_stale_publish_is_refused_after_another_admin_published(): void
    {
        $stale = Livewire::actingAs($this->first)->test('pages::admin.workflow-builder', ['template' => $this->template->id]);

        Livewire::actingAs($this->second)->test('pages::admin.workflow-builder', ['template' => $this->template->id])
            ->set('steps.0.name', 'Finance')
            ->call('requestPublish')->call('confirmPublish')->assertHasNoErrors();
        $this->assertSame(2, $this->template->fresh()->publishedVersion->version);

        $stale->set('steps.1.name', 'Director')
            ->call('requestPublish')
            ->assertHasErrors('template');
        $stale->call('confirmPublish')->assertHasErrors('template');

        $published = $this->template->fresh()->publishedVersion;
        $this->assertSame(2, $published->version);
        $this->assertSame(['Finance', 'Approval Level 2'], $published->steps->pluck('name')->all());
        $this->assertNull($this->template->fresh()->draftVersion);
    }

    public function test_stale_draft_save_is_refused_after_another_admin_saved_a_draft(): void
    {
        $stale = Livewire::actingAs($this->first)->test('pages::admin.workflow-builder', ['template' => $this->template->id]);

        Livewire::actingAs($this->second)->test('pages::admin.workflow-builder', ['template' => $this->template->id])
            ->set('steps.0.name', 'Finance')->call('saveDraft')->assertHasNoErrors();

        $stale->set('description', 'Edited elsewhere')->call('saveDraft')->assertHasErrors('template');

        $draft = $this->template->fresh()->draftVersion;
        $this->assertSame('Finance', $draft->steps->first()->name);
        $this->assertNotSame('Edited elsewhere', $this->template->fresh()->description);
    }

    public function test_templates_page_edit_is_refused_after_the_builder_saved(): void
    {
        $stale = Livewire::actingAs($this->first)->test('admin.workflow-templates.editor')
            ->call('edit', $this->template->id);

        Livewire::actingAs($this->second)->test('pages::admin.workflow-builder', ['template' => $this->template->id])
            ->set('steps.0.name', 'Finance')->call('saveDraft')->assertHasNoErrors();

        $stale->set('description', 'Edited elsewhere')->call('save')->assertHasErrors('template');
        $this->assertSame('Finance', $this->template->fresh()->draftVersion->steps->first()->name);

        // A fresh edit loads the latest version and saves normally.
        Livewire::actingAs($this->first)->test('admin.workflow-templates.editor')
            ->call('edit', $this->template->id)
            ->set('description', 'Edited after reload')->call('save')->assertHasNoErrors();
        $this->assertSame('Edited after reload', $this->template->fresh()->description);
    }

    public function test_one_admin_can_save_repeatedly_and_publish_without_a_conflict(): void
    {
        $builder = Livewire::actingAs($this->first)->test('pages::admin.workflow-builder', ['template' => $this->template->id]);

        $builder->set('steps.0.name', 'Finance')->call('saveDraft')->assertHasNoErrors()
            ->set('steps.1.name', 'Director')->call('saveDraft')->assertHasNoErrors()
            ->set('description', 'Two levels')->call('saveDraft')->assertHasNoErrors()
            ->call('requestPublish')->assertHasNoErrors()
            ->call('confirmPublish')->assertHasNoErrors()
            ->set('steps.0.name', 'Finance team')->call('saveDraft')->assertHasNoErrors();

        $this->assertSame(['Finance', 'Director'], $this->template->fresh()->publishedVersion->steps->pluck('name')->all());
        $this->assertSame('Finance team', $this->template->fresh()->draftVersion->steps->first()->name);
    }

    public function test_reopening_the_builder_picks_up_the_other_admins_version(): void
    {
        $stale = Livewire::actingAs($this->first)->test('pages::admin.workflow-builder', ['template' => $this->template->id]);
        Livewire::actingAs($this->second)->test('pages::admin.workflow-builder', ['template' => $this->template->id])
            ->set('steps.0.name', 'Finance')->call('saveDraft');
        $stale->call('saveDraft')->assertHasErrors('template');

        Livewire::actingAs($this->first)->test('pages::admin.workflow-builder', ['template' => $this->template->id])
            ->assertSet('steps.0.name', 'Finance')
            ->set('steps.1.name', 'Director')->call('saveDraft')->assertHasNoErrors();

        $this->assertSame(['Finance', 'Director'], $this->template->fresh()->draftVersion->steps->pluck('name')->all());
    }
}
