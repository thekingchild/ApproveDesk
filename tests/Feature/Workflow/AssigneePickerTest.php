<?php

namespace Tests\Feature\Workflow;

use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AssigneePickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_builder_does_not_render_the_full_user_directory(): void
    {
        $admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('admin');
        $approver = User::factory()->create(['name' => 'Bola Approver', 'email' => 'bola@example.test']);
        User::factory()->create(['name' => 'Hidden Colleague', 'email' => 'hidden-colleague@example.test']);
        $template = WorkflowTemplate::factory()->sequential([$approver])->create();

        $this->actingAs($admin)
            ->get(route('admin.workflow-templates.builder', $template))
            ->assertOk()
            ->assertSee('data-test="assignee-picker"', false)
            ->assertSee('Bola Approver')
            ->assertDontSee('hidden-colleague@example.test')
            ->assertDontSee('Hidden Colleague');
    }

    public function test_builder_search_adds_and_removes_assignee_chips(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create(['name' => 'Bola Approver']);
        $extra = User::factory()->create(['name' => 'Chioma Extra', 'email' => 'chioma-extra@example.test']);
        $template = WorkflowTemplate::factory()->sequential([$approver])->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->assertDontSee('Chioma Extra')
            ->set('assigneeQuery', 'Ch')
            ->assertSee('Chioma Extra')
            ->assertSee('data-test="assignee-results"', false)
            ->call('addAssignee', $extra->id)
            ->assertSet('steps.0.assigneeIds', [$approver->id, $extra->id])
            ->assertSet('assigneeQuery', '')
            ->call('removeAssignee', $extra->id)
            ->assertSet('steps.0.assigneeIds', [$approver->id]);
    }
}
