<?php

namespace Tests\Feature;

use App\DTOs\DelegationData;
use App\DTOs\WorkflowActionData;
use App\Enums\StepType;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\ReportService;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_submitter_dashboard_is_scoped_to_their_own_submissions(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $other = User::factory()->create()->assignRole('submitter');

        Submission::factory()->for($submitter, 'submitter')->create([
            'title' => 'Visible to its owner',
            'status' => SubmissionStatus::Approved,
        ]);
        Submission::factory()->for($other, 'submitter')->create([
            'title' => 'Belongs to someone else',
            'status' => SubmissionStatus::Rejected,
        ]);

        $report = app(ReportService::class)->dashboard($submitter);

        $this->assertFalse($report['organization']);
        $this->assertSame(1, $report['counts']['total']);
        $this->assertSame(1, $report['counts']['approved']);
        $this->assertSame(0, $report['counts']['rejected']);

        $this->actingAs($submitter)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Visible to its owner')
            ->assertDontSee('Belongs to someone else');
    }

    public function test_admin_dashboard_reports_organization_wide_activity(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $submitter = User::factory()->create()->assignRole('submitter');

        Submission::factory()->count(2)->for($submitter, 'submitter')->create([
            'status' => SubmissionStatus::InReview,
        ]);
        Submission::factory()->for($submitter, 'submitter')->create([
            'status' => SubmissionStatus::Approved,
        ]);

        $report = app(ReportService::class)->dashboard($admin);

        $this->assertTrue($report['organization']);
        $this->assertSame(3, $report['counts']['total']);
        $this->assertSame(2, $report['counts']['in_review']);
        $this->assertSame(1, $report['counts']['approved']);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Monitor organization activity and manage approval operations.')
            ->assertSee('data-test="administrator-home"', escape: false)
            ->assertSee('data-test="operations-health"', escape: false)
            ->assertSee('Median cycle time')
            ->assertSee('Returned rate')
            ->assertSee('SLA compliance')
            ->assertSee('Workload by approver');

        $this->actingAs($admin)
            ->get(route('submissions.index'))
            ->assertOk()
            ->assertSee('All submissions')
            ->assertSee($submitter->name);
    }

    public function test_approver_dashboard_counts_work_assigned_to_the_current_user(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        $otherApprover = User::factory()->create()->assignRole('approver');
        $submitter = User::factory()->create()->assignRole('submitter');

        $assignedTemplate = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $otherTemplate = WorkflowTemplate::factory()->sequential([$otherApprover])->create();

        $assigned = Submission::factory()->for($submitter, 'submitter')->create([
            'title' => 'Assigned approval request',
        ]);
        $other = Submission::factory()->for($submitter, 'submitter')->create();

        app(WorkflowEngine::class)->initialize($assigned);

        WorkflowInstance::factory()->create([
            'submission_id' => $other->id,
            'template_id' => $otherTemplate->id,
            'current_step_id' => $otherTemplate->steps()->firstOrFail()->id,
        ]);

        $report = app(ReportService::class)->dashboard($approver);

        $this->assertTrue($report['can_approve']);
        $this->assertSame(1, $report['action_required']);
        $this->assertSame(0, $report['pending_others']);
        $this->assertSame('approver', $report['home_mode']);

        $this->actingAs($approver)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-home-mode="approver"', escape: false)
            ->assertSee('data-test="priority-approvals"', escape: false)
            ->assertSee($assigned->title);
    }

    public function test_requester_home_prioritizes_drafts_and_returned_requests(): void
    {
        $requester = User::factory()->create(['name' => 'Sam Requester'])->assignRole('submitter');
        Submission::factory()->for($requester, 'submitter')->create(['status' => SubmissionStatus::Draft]);
        Submission::factory()->for($requester, 'submitter')->create(['status' => SubmissionStatus::Returned]);

        $report = app(ReportService::class)->dashboard($requester);

        $this->assertSame('requester', $report['home_mode']);
        $this->assertSame(2, $report['requester_attention']);

        $this->actingAs($requester)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Welcome back, Sam')
            ->assertSee('data-test="priority-requests"', escape: false);
    }

    public function test_user_with_requester_and_approver_capabilities_gets_mixed_home(): void
    {
        $user = User::factory()->create()->assignRole('department-head');

        $this->assertSame('mixed', app(ReportService::class)->dashboard($user)['home_mode']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-home-mode="mixed"', escape: false)
            ->assertSee('Act on assigned approvals, then continue your own requests.');
    }

    public function test_parallel_approver_moves_to_pending_others_after_acting(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        $colleague = User::factory()->create()->assignRole('approver');
        $submitter = User::factory()->create()->assignRole('submitter');
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Parallel,
            'assignee_ids' => [$approver->id, $colleague->id],
        ]);
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        $instance = app(WorkflowEngine::class)->initialize($submission);

        $before = app(ReportService::class)->dashboard($approver);
        $this->assertSame(1, $before['action_required']);

        app(WorkflowEngine::class)->processAction($instance, new WorkflowActionData($approver, WorkflowActionType::Approve));

        $after = app(ReportService::class)->dashboard($approver);
        $this->assertSame(0, $after['action_required']);
        $this->assertSame(1, $after['pending_others']);
    }

    public function test_grouped_tile_counts_match_each_tile_for_an_organization_approver(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $colleague = User::factory()->create()->assignRole('approver');
        $requester = User::factory()->create()->assignRole('submitter');
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Parallel,
            'assignee_ids' => [$admin->id, $colleague->id],
        ]);
        $engine = app(WorkflowEngine::class);

        $waiting = $engine->initialize(Submission::factory()->for($requester, 'submitter')->create());
        $pendingOthers = $engine->initialize(Submission::factory()->for($requester, 'submitter')->create());
        $engine->processAction($pendingOthers, new WorkflowActionData($admin, WorkflowActionType::Approve));
        $completed = $engine->initialize(Submission::factory()->for($requester, 'submitter')->create());
        $engine->processAction($completed, new WorkflowActionData($admin, WorkflowActionType::Approve));
        $engine->processAction($completed->refresh(), new WorkflowActionData($colleague, WorkflowActionType::Approve));

        Submission::factory()->for($admin, 'submitter')->create(['status' => SubmissionStatus::Draft]);
        Submission::factory()->for($admin, 'submitter')->create(['status' => SubmissionStatus::Returned]);
        Submission::factory()->for($admin, 'submitter')->create(['status' => SubmissionStatus::AwaitingInformation]);
        Submission::factory()->for($requester, 'submitter')->create(['status' => SubmissionStatus::Draft]);

        $report = app(ReportService::class)->dashboard($admin);

        $this->assertTrue($report['organization']);
        $this->assertSame(1, $report['action_required']);
        $this->assertSame([$waiting->id], $report['action_required_items']->modelKeys());
        $this->assertSame(1, $report['pending_others']);
        $this->assertSame(1, $report['completed_approvals']);
        $this->assertSame(1, $report['draft_count']);
        $this->assertSame(2, $report['needs_response_count']);
        $this->assertSame(3, $report['requester_attention']);
        $this->assertSame(7, $report['counts']['total']);
    }

    public function test_requester_tiles_reuse_the_status_counts_and_skip_empty_lookups(): void
    {
        $requester = User::factory()->create()->assignRole('submitter');
        Submission::factory()->for($requester, 'submitter')->create(['status' => SubmissionStatus::Draft]);
        Submission::factory()->for($requester, 'submitter')->create(['status' => SubmissionStatus::AwaitingInformation]);
        Submission::factory()->create(['status' => SubmissionStatus::Draft]);

        DB::enableQueryLog();
        $report = app(ReportService::class)->dashboard($requester->fresh());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(1, $report['draft_count']);
        $this->assertSame(1, $report['needs_response_count']);
        $this->assertCount(0, $report['action_required_items']);
        // The status counts feed every requester tile, and nothing queries an empty id list (RV-LAR-20).
        $sql = array_column($queries, 'query');
        $this->assertCount(1, array_filter($sql, fn (string $query): bool => str_contains($query, 'from "submissions"') && str_contains(strtolower($query), 'count(')));
        $this->assertCount(0, array_filter($sql, fn (string $query): bool => str_contains($query, '0 = 1')));
    }

    public function test_delegated_work_moves_from_the_delegator_dashboard_to_the_delegate(): void
    {
        $delegator = User::factory()->create()->assignRole('approver');
        $delegate = User::factory()->create()->assignRole('approver');
        $submitter = User::factory()->create()->assignRole('submitter');
        WorkflowTemplate::factory()->default()->sequential([$delegator])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        $instance = app(WorkflowEngine::class)->initialize($submission);

        app(WorkflowEngine::class)->delegate($instance, new DelegationData($delegator, $delegate, now()->addDay()));

        $delegatorReport = app(ReportService::class)->dashboard($delegator);
        $delegateReport = app(ReportService::class)->dashboard($delegate);

        $this->assertSame(0, $delegatorReport['action_required']);
        $this->assertSame(1, $delegateReport['action_required']);
    }
}
