<?php

namespace Tests\Feature\RequestTypes;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Enums\RequestFieldType;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RequestTypeWorkflowAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_two_request_types_can_share_one_template_and_unassigned_types_use_default(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create()->assignRole('approver');
        $shared = WorkflowTemplate::factory()->sequential([$approver])->create(['name' => 'Shared chain']);
        $default = WorkflowTemplate::factory()->default()->sequential([$approver])->create(['name' => 'Default chain']);
        $leave = $this->publishedType('Leave Request');
        $purchase = $this->publishedType('Purchase Request');

        Livewire::actingAs($admin)
            ->test('admin.request-types.builder')
            ->call('editType', $leave->id)
            ->set('workflowTemplateId', (string) $shared->id)
            ->call('save')
            ->assertHasNoErrors();

        Livewire::actingAs($admin)
            ->test('admin.request-types.builder')
            ->call('editType', $purchase->id)
            ->set('workflowTemplateId', (string) $shared->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($shared->id, $leave->fresh()->workflow_template_id);
        $this->assertSame($shared->id, $purchase->fresh()->workflow_template_id);

        $unassigned = Submission::factory()->create();
        $instance = app(WorkflowEngine::class)->initialize($unassigned);
        $this->assertSame($default->id, $instance->template_id);
    }

    public function test_submitting_a_custom_type_uses_the_assigned_template(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        $assigned = WorkflowTemplate::factory()->sequential([$approver])->create(['name' => 'Leave chain']);
        WorkflowTemplate::factory()->default()->sequential([$approver])->create(['name' => 'Default chain']);
        $leave = $this->publishedType('Leave Request');
        $leave->update(['workflow_template_id' => $assigned->id]);

        $submission = Submission::factory()->for($submitter, 'submitter')->create([
            'request_type_version_id' => $leave->publishedVersion->id,
        ]);

        $instance = app(WorkflowEngine::class)->initialize($submission);

        $this->assertSame($assigned->id, $instance->template_id);
    }

    public function test_changing_assignment_does_not_rewrite_in_flight_instances(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        $first = WorkflowTemplate::factory()->sequential([$approver])->create(['name' => 'First chain']);
        $second = WorkflowTemplate::factory()->sequential([$approver])->create(['name' => 'Second chain']);
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $leave = $this->publishedType('Leave Request');
        $leave->update(['workflow_template_id' => $first->id]);

        $submission = Submission::factory()->create([
            'request_type_version_id' => $leave->publishedVersion->id,
        ]);
        $instance = app(WorkflowEngine::class)->initialize($submission);

        $leave->update(['workflow_template_id' => $second->id]);

        $this->assertSame($first->id, $instance->fresh()->template_id);
        $this->assertSame(1, WorkflowInstance::query()->whereKey($instance->id)->where('template_id', $first->id)->count());
    }

    public function test_administrator_can_assign_workflow_to_general_request(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create();
        $assigned = WorkflowTemplate::factory()->sequential([$approver])->create(['name' => 'General chain']);
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $general = RequestTypeVersion::general()->requestType;

        Livewire::actingAs($admin)
            ->test('pages::admin.request-types')
            ->assertSee('Built-in forms')
            ->call('assignBuiltInWorkflow', $general->id, (string) $assigned->id)
            ->assertHasNoErrors();

        $this->assertSame($assigned->id, $general->fresh()->workflow_template_id);

        $submission = Submission::factory()->create();
        $instance = app(WorkflowEngine::class)->initialize($submission);
        $this->assertSame($assigned->id, $instance->template_id);
    }

    public function test_requester_type_chooser_does_not_offer_a_workflow_picker(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $this->publishedType('Leave Request');

        $this->actingAs($submitter)
            ->get(route('submissions.choose-type'))
            ->assertOk()
            ->assertSee('Choose a request type')
            ->assertDontSee('Workflow template')
            ->assertDontSee('data-test="request-type-workflow"', false);
    }

    private function publishedType(string $name): RequestType
    {
        $type = RequestType::factory()->create(['name' => $name]);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'reason',
            'label' => 'Reason',
            'type' => RequestFieldType::LongText,
        ]);
        app(PublishRequestTypeVersionAction::class)->execute($version);

        return $type->fresh(['publishedVersion']);
    }
}
