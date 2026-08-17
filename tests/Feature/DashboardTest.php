<?php

namespace Tests\Feature;

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
            ->assertSee('Organization-wide approval activity.');

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

        $assigned = Submission::factory()->for($submitter, 'submitter')->create();
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

        app(WorkflowEngine::class)->processAction($instance, $approver, WorkflowActionType::Approve);

        $after = app(ReportService::class)->dashboard($approver);
        $this->assertSame(0, $after['action_required']);
        $this->assertSame(1, $after['pending_others']);
    }
}
