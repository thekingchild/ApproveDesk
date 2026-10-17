<?php

namespace Tests\Feature\Workflow;

use App\Contracts\ExternalAssigneeResolver;
use App\DTOs\WorkflowActionData;
use App\Enums\AssigneeType;
use App\Enums\GroupCompletion;
use App\Enums\RequestFieldType;
use App\Enums\RequestTypeVersionStatus;
use App\Enums\StepType;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Department;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\SubmissionFieldValue;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\Workflow\AssigneeResolver;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssigneeResolverContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_every_assignee_type_has_a_resolver_contract(): void
    {
        $this->assertSame(
            [
                AssigneeType::User,
                AssigneeType::Role,
                AssigneeType::Department,
                AssigneeType::RequesterManager,
                AssigneeType::DepartmentManager,
                AssigneeType::FormField,
                AssigneeType::External,
            ],
            AssigneeType::cases(),
        );
    }

    public function test_named_user_resolver_returns_only_active_selected_users(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $step = $this->step(AssigneeType::User, [$active->id, $inactive->id]);

        $resolved = app(AssigneeResolver::class)->resolve($step);

        $this->assertTrue($resolved->contains($active));
        $this->assertFalse($resolved->contains($inactive));
    }

    public function test_role_resolver_expands_active_role_members(): void
    {
        $member = User::factory()->create()->assignRole('approver');
        $roleId = Role::query()->where('name', 'approver')->value('id');
        $step = $this->step(AssigneeType::Role, [(int) $roleId]);

        $this->assertTrue(app(AssigneeResolver::class)->resolve($step)->contains($member));
    }

    public function test_department_resolver_expands_active_members(): void
    {
        $department = Department::factory()->create();
        $member = User::factory()->create(['department_id' => $department->id]);
        $outsider = User::factory()->create();
        $step = $this->step(AssigneeType::Department, [$department->id]);

        $resolved = app(AssigneeResolver::class)->resolve($step);
        $this->assertTrue($resolved->contains($member));
        $this->assertFalse($resolved->contains($outsider));
    }

    public function test_requester_manager_resolver_uses_the_submitter_line_manager(): void
    {
        $manager = User::factory()->create();
        $requester = User::factory()->create(['manager_id' => $manager->id]);
        $submission = Submission::factory()->for($requester, 'submitter')->create();
        $step = $this->step(AssigneeType::RequesterManager, []);

        $this->assertTrue(app(AssigneeResolver::class)->resolve($step, $submission)->contains($manager));
        $this->assertTrue($step->assignedUsers($submission)->contains($manager));
    }

    public function test_department_manager_resolver_uses_the_request_department_manager(): void
    {
        $manager = User::factory()->create();
        $department = Department::factory()->create(['manager_id' => $manager->id]);
        $submission = Submission::factory()->for($department)->create();
        $step = $this->step(AssigneeType::DepartmentManager, []);

        $this->assertTrue(app(AssigneeResolver::class)->resolve($step, $submission)->contains($manager));
    }

    public function test_form_field_resolver_uses_the_selected_user_field(): void
    {
        $approver = User::factory()->create();
        $type = RequestType::factory()->create();
        $version = RequestTypeVersion::factory()->for($type)->create();
        $field = RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'reviewer',
            'type' => RequestFieldType::User,
        ]);
        $version->updateQuietly([
            'status' => RequestTypeVersionStatus::Published,
            'published_at' => now(),
        ]);
        $submission = Submission::factory()->create(['request_type_version_id' => $version->id]);
        SubmissionFieldValue::query()->create([
            'submission_id' => $submission->id,
            'request_field_definition_id' => $field->id,
            'user_value_id' => $approver->id,
        ]);
        $step = $this->step(AssigneeType::FormField, [], ['field_key' => 'reviewer']);

        $this->assertTrue(app(AssigneeResolver::class)->resolve($step, $submission->fresh(['fieldValues.field', 'fieldValues.userValue']))->contains($approver));
    }

    public function test_external_resolver_interface_is_invoked_by_key(): void
    {
        $user = User::factory()->create();
        $step = $this->step(AssigneeType::External, [], ['resolver' => 'contract-test']);
        $resolver = new AssigneeResolver([new class($user) implements ExternalAssigneeResolver
        {
            public function __construct(private User $user) {}

            public function key(): string
            {
                return 'contract-test';
            }

            public function resolve(WorkflowTemplateStep $step, Submission $submission): Collection
            {
                return collect([$this->user]);
            }
        }]);

        $this->assertTrue($resolver->resolve($step, Submission::factory()->create())->contains($user));
        $this->assertTrue($resolver->resolve($this->step(AssigneeType::External, [], ['resolver' => 'missing']), Submission::factory()->create())->isEmpty());
    }

    public function test_first_response_all_and_quorum_completion_rules(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = User::factory()->create();
        $engine = app(WorkflowEngine::class);

        $first = WorkflowTemplate::factory()->default()->sequential([$a, $b])->create();
        $first->steps->first()->updateQuietly(['assignee_ids' => [$a->id, $b->id], 'completion_rule' => GroupCompletion::FirstResponse->value]);
        $instance = $engine->initialize(Submission::factory()->create());
        $engine->processAction($instance, new WorkflowActionData($a, WorkflowActionType::Approve));
        $this->assertSame(2, $instance->fresh()->currentStep?->step_order);

        $all = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($all, 1)->create([
            'step_type' => StepType::Parallel,
            'assignee_ids' => [$a->id, $b->id],
            'completion_rule' => GroupCompletion::All->value,
        ]);
        $open = $engine->initialize(Submission::factory()->create());
        $engine->processAction($open, new WorkflowActionData($a, WorkflowActionType::Approve));
        $this->assertSame(WorkflowStatus::Active, $open->fresh()->status);
        $engine->processAction($open->fresh(), new WorkflowActionData($b, WorkflowActionType::Approve));
        $this->assertSame(WorkflowStatus::Completed, $open->fresh()->status);

        $quorum = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($quorum, 1)->create([
            'name' => 'Committee',
            'step_type' => StepType::Quorum,
            'quorum_count' => 2,
            'assignee_ids' => [$a->id, $b->id, $c->id],
            'completion_rule' => GroupCompletion::Quorum->value,
        ]);
        $committee = $engine->initialize(Submission::factory()->create());
        $engine->processAction($committee, new WorkflowActionData($a, WorkflowActionType::Approve));
        $this->assertSame(WorkflowStatus::Active, $committee->fresh()->status);
        $engine->processAction($committee->fresh(), new WorkflowActionData($b, WorkflowActionType::Approve));
        $this->assertSame(WorkflowStatus::Completed, $committee->fresh()->status);
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>|null  $config
     */
    private function step(AssigneeType $type, array $ids, ?array $config = null): WorkflowTemplateStep
    {
        $step = new WorkflowTemplateStep([
            'name' => 'Contract step',
            'step_order' => 1,
            'step_type' => StepType::Sequential,
            'assignee_type' => $type,
            'assignee_ids' => $ids,
            'assignee_config' => $config,
            'allow_delegation' => true,
        ]);
        $step->syncOriginal();

        return $step;
    }
}
