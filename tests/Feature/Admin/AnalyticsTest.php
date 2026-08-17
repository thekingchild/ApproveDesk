<?php

namespace Tests\Feature\Admin;

use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\AnalyticsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $actor;

    private WorkflowTemplate $template;

    private WorkflowTemplateStep $step;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-17 12:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->actor = User::factory()->create()->assignRole('approver');
        $this->template = WorkflowTemplate::factory()->create(['name' => 'Purchase approvals']);
        $this->step = WorkflowTemplateStep::factory()->forTemplate($this->template, 1)->create([
            'name' => 'Finance review',
            'timeout_hours' => 24,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_analytics_reconciles_outcomes_turnaround_and_bottlenecks(): void
    {
        $this->resolvedWorkflow(WorkflowStatus::Completed, WorkflowActionType::Approve, '2026-08-10 08:00:00', '2026-08-10 12:00:00');
        $this->resolvedWorkflow(WorkflowStatus::Rejected, WorkflowActionType::Reject, '2026-08-12 08:00:00', '2026-08-12 16:00:00');
        $active = $this->submission(SubmissionStatus::InReview, 'Waiting purchase');
        WorkflowInstance::factory()->create([
            'submission_id' => $active->id,
            'template_id' => $this->template->id,
            'current_step_id' => $this->step->id,
            'status' => WorkflowStatus::Active,
            'started_at' => '2026-08-15 00:00:00',
            'step_started_at' => '2026-08-15 00:00:00',
        ]);

        $report = app(AnalyticsService::class)->dashboard(30);

        $this->assertSame(3, $report['summary']['started']);
        $this->assertSame(1, $report['summary']['active']);
        $this->assertSame(2, $report['summary']['resolved']);
        $this->assertSame(1, $report['summary']['approved']);
        $this->assertSame(1, $report['summary']['rejected']);
        $this->assertSame(50.0, $report['summary']['approval_rate']);
        $this->assertSame(360, $report['summary']['average_turnaround_minutes']);
        $this->assertSame(360, $report['summary']['median_turnaround_minutes']);
        $this->assertSame(1, $report['summary']['overdue']);
        $this->assertCount(30, $report['daily']);
        $this->assertSame(3, collect($report['daily'])->sum('started'));
        $this->assertSame(2, collect($report['daily'])->sum('resolved'));

        $bottleneck = $report['bottlenecks'][0];
        $this->assertSame('Finance review', $bottleneck['step']);
        $this->assertSame(2, $bottleneck['completed_samples']);
        $this->assertSame(360, $bottleneck['average_minutes']);
        $this->assertSame(1, $bottleneck['open']);
        $this->assertSame(1, $bottleneck['overdue']);
        $this->assertSame('Waiting purchase', $report['slowest_active'][0]['title']);
    }

    public function test_analytics_page_is_report_permission_protected_and_period_is_reactive(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)
            ->get(route('admin.analytics.index'))
            ->assertForbidden();

        $this->actingAs($admin = $this->admin)
            ->get(route('admin.analytics.index'))
            ->assertOk()
            ->assertSee('Approval analytics')
            ->assertSee('Daily workflow activity')
            ->assertSee('Step bottlenecks');

        Livewire::actingAs($admin)
            ->test('pages::admin.analytics')
            ->set('days', 7)
            ->assertSet('days', 7)
            ->set('days', 8)
            ->assertSet('days', 30);
    }

    public function test_admin_can_export_the_same_analytics_as_csv(): void
    {
        $this->resolvedWorkflow(WorkflowStatus::Completed, WorkflowActionType::Approve, '2026-08-10 08:00:00', '2026-08-10 12:00:00');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.analytics.export', ['days' => 30]));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('approval-analytics-2026-07-19-to-2026-08-17.csv');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('ApproveDesk approval analytics', $csv);
        $this->assertStringContainsString('"Purchase approvals","Finance review"', $csv);

        $this->actingAs(User::factory()->create()->assignRole('submitter'))
            ->get(route('admin.analytics.export', ['days' => 30]))
            ->assertForbidden();
    }

    public function test_export_rejects_an_unsupported_period(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.analytics.export', ['days' => 31]))
            ->assertSessionHasErrors('days');
    }

    private function resolvedWorkflow(
        WorkflowStatus $status,
        WorkflowActionType $action,
        string $startedAt,
        string $completedAt,
    ): WorkflowInstance {
        $submission = $this->submission(
            $status === WorkflowStatus::Completed ? SubmissionStatus::Approved : SubmissionStatus::Rejected,
        );
        $instance = WorkflowInstance::factory()->create([
            'submission_id' => $submission->id,
            'template_id' => $this->template->id,
            'current_step_id' => null,
            'status' => $status,
            'started_at' => $startedAt,
            'step_started_at' => $startedAt,
            'completed_at' => $completedAt,
        ]);
        WorkflowAction::factory()->create([
            'instance_id' => $instance->id,
            'step_id' => $this->step->id,
            'actor_id' => $this->actor->id,
            'action' => $action,
            'created_at' => $completedAt,
        ]);

        return $instance;
    }

    private function submission(SubmissionStatus $status, string $title = 'Purchase request'): Submission
    {
        return Submission::factory()->create([
            'status' => $status,
            'title' => $title,
        ]);
    }
}
