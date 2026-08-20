<?php

namespace Tests\Feature\Admin;

use App\Enums\SubmissionStatus;
use App\Enums\TimeoutCalendar;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Setting;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
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
            'assignee_ids' => [$this->actor->id],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_analytics_reconciles_outcomes_turnaround_and_bottlenecks(): void
    {
        $completed = $this->resolvedWorkflow(WorkflowStatus::Completed, WorkflowActionType::Approve, '2026-08-10 08:00:00', '2026-08-10 12:00:00');
        WorkflowAction::factory()->create([
            'instance_id' => $completed->id,
            'step_id' => $this->step->id,
            'actor_id' => $this->actor->id,
            'action' => WorkflowActionType::Return,
            'created_at' => '2026-08-10 10:00:00',
        ]);
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
        $this->assertSame(1, $report['summary']['returned']);
        $this->assertSame(33.3, $report['summary']['returned_rate']);
        $this->assertSame(0.0, $report['summary']['sla_compliance']);
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
        $this->assertSame($this->actor->name, $report['workload_by_approver'][0]['approver']);
        $this->assertSame(1, $report['workload_by_approver'][0]['active']);
        $this->assertSame(1, $report['workload_by_approver'][0]['overdue']);
    }

    public function test_business_calendar_overdue_is_not_scored_as_elapsed(): void
    {
        Carbon::setTestNow('2026-12-26 12:00:00');
        Setting::put('calendar.working_days', '1,2,3,4,5');
        Setting::put('calendar.holidays', '2026-12-25');
        $this->step->updateQuietly([
            'timeout_hours' => 24,
            'timeout_calendar' => TimeoutCalendar::Business->value,
        ]);
        $active = $this->submission(SubmissionStatus::InReview, 'Weekend purchase');
        WorkflowInstance::factory()->create([
            'submission_id' => $active->id,
            'template_id' => $this->template->id,
            'current_step_id' => $this->step->id,
            'status' => WorkflowStatus::Active,
            'started_at' => '2026-12-24 09:00:00',
            'step_started_at' => '2026-12-24 09:00:00',
        ]);

        $report = app(AnalyticsService::class)->dashboard(7);

        $this->assertSame(1, $report['summary']['active']);
        $this->assertSame(0, $report['summary']['overdue']);
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
            ->assertSee('View daily activity data table')
            ->assertSee('Step bottlenecks')
            ->assertSee('Workload by approver')
            ->assertSee('Excel workbook')
            ->assertSee('PDF report');

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
            ->get(route('admin.analytics.export', ['days' => 30, 'format' => 'csv']));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('approval-analytics-2026-07-19-to-2026-08-17.csv');

        $csv = $response->getContent();
        $this->assertIsString($csv);
        $this->assertStringContainsString('ApproveDesk approval analytics', $csv);
        $this->assertStringContainsString('"Purchase approvals","Finance review"', $csv);

        $this->actingAs(User::factory()->create()->assignRole('submitter'))
            ->get(route('admin.analytics.export', ['days' => 30, 'format' => 'csv']))
            ->assertForbidden();
    }

    public function test_admin_can_export_a_formatted_excel_workbook(): void
    {
        $this->resolvedWorkflow(WorkflowStatus::Completed, WorkflowActionType::Approve, '2026-08-10 08:00:00', '2026-08-10 12:00:00');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.analytics.export', ['days' => 30, 'format' => 'xlsx']));

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('approval-analytics-2026-07-19-to-2026-08-17.xlsx');

        $contents = $response->getContent();
        $this->assertIsString($contents);
        $temporary = tempnam(sys_get_temp_dir(), 'analytics-test-');
        $this->assertNotFalse($temporary);

        try {
            file_put_contents($temporary, $contents);
            $workbook = IOFactory::load($temporary);
            $this->assertSame(['Summary', 'Daily Activity', 'Bottlenecks', 'Active Workflows'], $workbook->getSheetNames());
            $this->assertSame('ApproveDesk approval analytics', $workbook->getSheetByName('Summary')?->getCell('A1')->getValue());
            $this->assertSame(1, $workbook->getSheetByName('Summary')?->getCell('B5')->getValue());
            $this->assertSame('Finance review', $workbook->getSheetByName('Bottlenecks')?->getCell('B2')->getValue());
            $this->assertSame('0.0%', $workbook->getSheetByName('Summary')?->getStyle('B10')->getNumberFormat()->getFormatCode());
            $workbook->disconnectWorksheets();
        } finally {
            unlink($temporary);
        }
    }

    public function test_admin_can_export_a_rendered_pdf_report(): void
    {
        $this->resolvedWorkflow(WorkflowStatus::Completed, WorkflowActionType::Approve, '2026-08-10 08:00:00', '2026-08-10 12:00:00');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.analytics.export', ['days' => 30, 'format' => 'pdf']));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('approval-analytics-2026-07-19-to-2026-08-17.pdf');

        $contents = $response->getContent();
        $this->assertIsString($contents);
        $this->assertStringStartsWith('%PDF-', $contents);
        $this->assertGreaterThan(5_000, strlen($contents));
    }

    public function test_export_rejects_an_unsupported_period(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.analytics.export', ['days' => 31, 'format' => 'docx']))
            ->assertSessionHasErrors(['days', 'format']);
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
