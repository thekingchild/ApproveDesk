<?php

namespace Tests\Feature\Workflow;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\WorkflowTemplates\CreateWorkflowTemplateAction;
use App\Actions\WorkflowTemplates\SaveWorkflowSelectionRulesAction;
use App\Contracts\ExternalAssigneeResolver;
use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\GroupCompletion;
use App\Enums\OutcomeBehavior;
use App\Enums\RequestFieldType;
use App\Enums\StepType;
use App\Enums\TimeoutCalendar;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Department;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\SubmissionFieldValue;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\BusinessCalendar;
use App\Services\Workflow\WorkflowTemplateSelector;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DynamicRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_named_user_role_and_department_resolvers(): void
    {
        $user = User::factory()->create()->assignRole('approver');
        $department = Department::factory()->create();
        $member = User::factory()->create(['department_id' => $department->id]);
        $role = Role::query()->where('name', 'approver')->firstOrFail();

        $named = WorkflowTemplate::factory()->sequential([$user])->create();
        $this->assertTrue($named->steps->first()->assignedUsers()->contains($user));

        $roleStep = $this->stepTemplate(AssigneeType::Role, [$role->id], [$user]);
        $this->assertTrue($roleStep->assignedUsers()->contains($user));

        $deptStep = $this->stepTemplate(AssigneeType::Department, [$department->id], [$member]);
        $this->assertTrue($deptStep->assignedUsers()->contains($member));
        $this->assertFalse($deptStep->assignedUsers()->contains($user));
    }

    public function test_requester_and_department_manager_resolvers(): void
    {
        $lineManager = User::factory()->create();
        $deptManager = User::factory()->create();
        $department = Department::factory()->create(['manager_id' => $deptManager->id]);
        $requester = User::factory()->create([
            'manager_id' => $lineManager->id,
            'department_id' => $department->id,
        ]);
        $submission = Submission::factory()->for($requester, 'submitter')->for($department)->create();

        $lineStep = $this->stepTemplate(AssigneeType::RequesterManager, [], [$lineManager]);
        $this->assertTrue($lineStep->assignedUsers($submission)->contains($lineManager));

        $deptStep = $this->stepTemplate(AssigneeType::DepartmentManager, [], [$deptManager]);
        $this->assertTrue($deptStep->assignedUsers($submission)->contains($deptManager));
    }

    public function test_form_field_and_external_resolvers(): void
    {
        $approver = User::factory()->create();
        $type = $this->publishedTypeWithUserField();
        $field = $type->publishedVersion->fields->firstWhere('key', 'reviewer');
        $submission = Submission::factory()->create([
            'request_type_version_id' => $type->publishedVersion->id,
        ]);
        SubmissionFieldValue::query()->create([
            'submission_id' => $submission->id,
            'request_field_definition_id' => $field->id,
            'user_value_id' => $approver->id,
        ]);

        $step = $this->stepTemplate(AssigneeType::FormField, [], [$approver], ['field_key' => 'reviewer']);
        $this->assertTrue($step->assignedUsers($submission->fresh(['fieldValues.field', 'fieldValues.userValue']))->contains($approver));

        $externalUser = User::factory()->create(['email' => 'external@example.test']);
        $externalStep = $this->stepTemplate(AssigneeType::External, [], [$externalUser], ['resolver' => 'test-owner']);
        $resolver = new AssigneeResolver([new class($externalUser) implements ExternalAssigneeResolver
        {
            public function __construct(private User $user) {}

            public function key(): string
            {
                return 'test-owner';
            }

            public function resolve(WorkflowTemplateStep $step, Submission $submission): Collection
            {
                return collect([$this->user]);
            }
        }]);
        $this->assertTrue($resolver->resolve($externalStep, $submission)->contains($externalUser));
    }

    public function test_first_response_all_and_quorum_completion(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $template = app(CreateWorkflowTemplateAction::class)->execute(new WorkflowTemplateData(
            name: 'Group completion',
            description: null,
            submissionTypes: [],
            isDefault: true,
            isActive: true,
            steps: [
                new WorkflowTemplateStepData(
                    name: 'Either',
                    stepType: StepType::Sequential,
                    quorumCount: null,
                    assigneeType: AssigneeType::User,
                    assigneeIds: [$first->id, $second->id],
                    allowDelegation: true,
                    completionRule: GroupCompletion::FirstResponse,
                ),
            ],
        ), User::factory()->create());

        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        app(WorkflowEngine::class)->processAction($instance, $first, WorkflowActionType::Approve);
        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);

        $all = app(CreateWorkflowTemplateAction::class)->execute(new WorkflowTemplateData(
            name: 'All must act',
            description: null,
            submissionTypes: [],
            isDefault: true,
            isActive: true,
            steps: [
                new WorkflowTemplateStepData(
                    name: 'Both',
                    stepType: StepType::Parallel,
                    quorumCount: null,
                    assigneeType: AssigneeType::User,
                    assigneeIds: [$first->id, $second->id],
                    allowDelegation: true,
                    completionRule: GroupCompletion::All,
                ),
            ],
        ), User::factory()->create());

        $open = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        app(WorkflowEngine::class)->processAction($open, $first, WorkflowActionType::Approve);
        $this->assertSame(WorkflowStatus::Active, $open->fresh()->status);
        app(WorkflowEngine::class)->processAction($open->fresh(), $second, WorkflowActionType::Approve);
        $this->assertSame(WorkflowStatus::Completed, $open->fresh()->status);
        $this->assertSame($all->id, $open->template_id);
    }

    public function test_business_calendar_skips_weekend(): void
    {
        Setting::put('calendar.working_days', '1,2,3,4,5');
        Setting::put('calendar.holidays', '2026-12-25');
        $start = Carbon::parse('2026-12-24 09:00:00'); // Thursday
        $deadline = app(BusinessCalendar::class)->addBusinessHours($start, 24);

        $this->assertSame('2026-12-28', $deadline->toDateString());
    }

    public function test_approve_with_conditions_requires_comment_and_advances(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        try {
            app(WorkflowEngine::class)->processAction($instance, $approver, WorkflowActionType::ApproveWithConditions);
            $this->fail('Approve with conditions must require a comment.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('comment', strtolower($exception->getMessage()));
        }

        $this->assertSame(WorkflowStatus::Active, $instance->fresh()->status);

        app(WorkflowEngine::class)->processAction(
            $instance->fresh(),
            $approver,
            WorkflowActionType::ApproveWithConditions,
            'Approved if finance signs the invoice.',
        );

        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);
        $this->assertDatabaseHas('workflow_actions', [
            'action' => WorkflowActionType::ApproveWithConditions->value,
            'comment' => 'Approved if finance signs the invoice.',
        ]);
    }

    public function test_custom_outcome_maps_to_reject(): void
    {
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        app(CreateWorkflowTemplateAction::class)->execute(new WorkflowTemplateData(
            name: 'Custom outcomes',
            description: null,
            submissionTypes: [],
            isDefault: true,
            isActive: true,
            steps: [
                new WorkflowTemplateStepData('Review', StepType::Sequential, null, AssigneeType::User, [$approver->id], true),
            ],
            outcomes: [[
                'key' => 'decline_with_advice',
                'label' => 'Decline with advice',
                'behavior' => OutcomeBehavior::Reject->value,
                'requires_comment' => true,
            ]],
        ), $admin);

        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        app(WorkflowEngine::class)->processAction(
            $instance,
            $approver,
            WorkflowActionType::Approve,
            'Not a fit.',
            'decline_with_advice',
        );

        $this->assertSame(WorkflowStatus::Rejected, $instance->fresh()->status);
        $this->assertDatabaseHas('workflow_actions', [
            'action' => WorkflowActionType::Reject->value,
            'outcome_key' => 'decline_with_advice',
        ]);
    }

    public function test_selection_rules_beat_assignment_and_default_in_priority_order(): void
    {
        $approver = User::factory()->create();
        $admin = User::factory()->create()->assignRole('admin');
        $high = WorkflowTemplate::factory()->sequential([$approver])->create(['name' => 'High value']);
        $assigned = WorkflowTemplate::factory()->sequential([$approver])->create(['name' => 'Assigned']);
        $default = WorkflowTemplate::factory()->default()->sequential([$approver])->create(['name' => 'Default']);
        $type = $this->publishedTypeWithUserField();
        $type->update(['workflow_template_id' => $assigned->id]);

        app(SaveWorkflowSelectionRulesAction::class)->execute($type, [
            [
                'name' => 'High amount',
                'priority' => 1,
                'workflow_template_id' => $high->id,
                'is_active' => true,
                'field' => 'metadata.amount',
                'operator' => 'gte',
                'value' => 10000,
            ],
        ], $admin);

        $highSubmission = Submission::factory()->create([
            'request_type_version_id' => $type->publishedVersion->id,
            'metadata' => ['amount' => 15000],
        ]);
        $highInstance = app(WorkflowEngine::class)->initialize($highSubmission);
        $this->assertSame($high->id, $highInstance->template_id);
        $this->assertStringContainsString('High amount', (string) $highInstance->selection_explanation);

        $lowSubmission = Submission::factory()->create([
            'request_type_version_id' => $type->publishedVersion->id,
            'metadata' => ['amount' => 50],
        ]);
        $lowInstance = app(WorkflowEngine::class)->initialize($lowSubmission);
        $this->assertSame($assigned->id, $lowInstance->template_id);

        $unassigned = Submission::factory()->create();
        $fallback = app(WorkflowEngine::class)->initialize($unassigned);
        $this->assertSame($default->id, $fallback->template_id);

        $trace = app(WorkflowTemplateSelector::class)->explain($type->fresh(['workflowTemplate', 'selectionRules.template']), [
            'metadata' => ['amount' => 15000],
        ]);
        $this->assertTrue($trace[0]['matched']);
        $this->assertSame('High value', $trace[0]['template']);
    }

    public function test_elapsed_deadline_is_unchanged(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $template->steps->first()->updateQuietly([
            'timeout_hours' => 2,
            'timeout_calendar' => TimeoutCalendar::Elapsed->value,
        ]);
        Carbon::setTestNow('2026-12-24 09:00:00');
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        $this->assertSame('2026-12-24 11:00:00', $instance->deadlineAt()?->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>|null  $config
     */
    private function stepTemplate(AssigneeType $type, array $ids, array $users, ?array $config = null): WorkflowTemplateStep
    {
        $template = WorkflowTemplate::factory()->sequential($users)->create();
        $step = $template->publishedVersion->steps->first();
        $step->updateQuietly([
            'assignee_type' => $type,
            'assignee_ids' => $ids,
            'assignee_config' => $config,
        ]);

        return $step->fresh();
    }

    private function publishedTypeWithUserField(): RequestType
    {
        $type = RequestType::factory()->create(['name' => 'Expense']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'reviewer',
            'label' => 'Reviewer',
            'type' => RequestFieldType::User,
        ]);
        app(PublishRequestTypeVersionAction::class)->execute($version);

        return $type->fresh(['publishedVersion.fields']);
    }
}
