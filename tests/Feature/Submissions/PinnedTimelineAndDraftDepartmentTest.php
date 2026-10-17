<?php

namespace Tests\Feature\Submissions;

use App\DTOs\WorkflowActionData;
use App\Enums\TimelineStepState;
use App\Enums\WorkflowActionType;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-BUG-28: the request page timeline follows the instance's pinned workflow
 * version. RV-BUG-32: editing a General Request draft keeps its department.
 */
class PinnedTimelineAndDraftDepartmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_timeline_shows_the_pinned_version_after_a_new_version_is_published(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $first = User::factory()->create()->assignRole('approver');
        $second = User::factory()->create()->assignRole('approver');
        $template = WorkflowTemplate::factory()->default()->sequential([$first, $second])->create();
        $submitter = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->create(['submitter_id' => $submitter->id]);
        $instance = app(WorkflowEngine::class)->initialize($submission);
        app(WorkflowEngine::class)->processAction($instance->fresh(), new WorkflowActionData($first, WorkflowActionType::Approve));

        Livewire::actingAs($admin)->test('pages::admin.workflow-builder', ['template' => $template->id])
            ->set('steps.0.name', 'Finance')->set('steps.1.name', 'Director')
            ->call('requestPublish')->call('confirmPublish')->assertHasNoErrors();
        $this->assertSame(2, $template->fresh()->publishedVersion->version);

        $timeline = Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->assertSee('Approval Level 1')
            ->assertDontSee('Finance')
            ->instance()->timeline;

        $this->assertSame(['Approval Level 1', 'Approval Level 2'], array_column($timeline, 'name'));
        $this->assertSame([TimelineStepState::Done, TimelineStepState::Active], array_column($timeline, 'state'));
        $this->assertSame($second->name, $timeline[1]['actors']);
    }

    public function test_editing_a_general_draft_keeps_its_department(): void
    {
        WorkflowTemplate::factory()->default()->sequential([User::factory()->create()])->create();
        $department = Department::query()->create(['name' => 'Operations']);
        $user = User::factory()->create(['department_id' => $department->id])->assignRole('submitter');

        Livewire::actingAs($user)->test('pages::submissions.create')
            ->set('title', 'Printer')->call('saveDraft')->assertHasNoErrors();
        $draft = Submission::query()->where('submitter_id', $user->id)->sole();
        $this->assertSame($department->id, $draft->department_id);

        // The requester later moves department: the request keeps the one it was raised under.
        $user->update(['department_id' => Department::query()->create(['name' => 'Finance'])->id]);

        Livewire::actingAs($user)->test('pages::submissions.create', ['submission' => $draft])
            ->set('title', 'Printer v2')->call('saveDraft')->assertHasNoErrors();

        $this->assertSame('Printer v2', $draft->fresh()->title);
        $this->assertSame($department->id, $draft->fresh()->department_id);
    }

    public function test_editing_a_returned_general_request_keeps_its_department(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $department = Department::query()->create(['name' => 'Operations']);
        $user = User::factory()->create(['department_id' => $department->id])->assignRole('submitter');

        Livewire::actingAs($user)->test('pages::submissions.create')
            ->set('title', 'Printer')->call('submit')->assertHasNoErrors();
        $submission = Submission::query()->where('submitter_id', $user->id)->sole();
        app(WorkflowEngine::class)->processAction($submission->workflowInstance, new WorkflowActionData($approver, WorkflowActionType::Return, 'Add the model number.'));

        Livewire::actingAs($user)->test('pages::submissions.create', ['submission' => $submission->fresh()])
            ->set('title', 'Printer XL-200')->call('submit')->assertHasNoErrors();

        $this->assertSame('Printer XL-200', $submission->fresh()->title);
        $this->assertSame($department->id, $submission->fresh()->department_id);
    }
}
