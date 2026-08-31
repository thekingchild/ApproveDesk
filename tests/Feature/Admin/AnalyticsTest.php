<?php

namespace Tests\Feature\Admin;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\RequestTypes\StoreSubmissionFieldValuesAction;
use App\Enums\RequestFieldType;
use App\Enums\SubmissionStatus;
use App\Enums\TimeoutCalendar;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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

    public function test_csv_export_prefixes_formula_injection_payloads_with_an_apostrophe(): void
    {
        $payloads = $this->seedFormulaInjectionAnalytics();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.analytics.export', ['days' => 30, 'format' => 'csv']));

        $response->assertOk();
        $csv = $response->getContent();
        $this->assertIsString($csv);

        $cells = $this->csvCells($csv);

        foreach ($payloads['dangerous'] as $payload) {
            $this->assertContains("'".$payload, $cells, 'CSV is missing prefixed formula payload: '.$this->visible($payload));
            $this->assertNotContains($payload, $cells, 'CSV still contains a raw formula cell: '.$this->visible($payload));
        }

        $this->assertContains('Benign note', $cells);
        $this->assertContains('ApproveDesk approval analytics', $cells);
        $this->assertContains('Notes', $cells);
        $this->assertNotContains("'Benign note", $cells);
        $this->assertNotContains("'Notes", $cells);
        $this->assertContains((string) $payloads['started'], $cells);
        $this->assertNotContains("'".$payloads['started'], $cells);
    }

    public function test_xlsx_export_stores_formula_like_values_as_strings_not_formulas(): void
    {
        $payloads = $this->seedFormulaInjectionAnalytics();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.analytics.export', ['days' => 30, 'format' => 'xlsx']));

        $response->assertOk();
        $contents = $response->getContent();
        $this->assertIsString($contents);
        $temporary = tempnam(sys_get_temp_dir(), 'analytics-formula-');
        $this->assertNotFalse($temporary);

        try {
            file_put_contents($temporary, $contents);
            $workbook = IOFactory::load($temporary);
            $this->assertSame(['Summary', 'Daily Activity', 'Bottlenecks', 'Active Workflows', 'Request Fields'], $workbook->getSheetNames());

            $seen = [];
            $stringValues = [];
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $iterator = $row->getCellIterator();
                    $iterator->setIterateOnlyExistingCells(true);
                    foreach ($iterator as $cell) {
                        $this->assertNotSame(
                            DataType::TYPE_FORMULA,
                            $cell->getDataType(),
                            $sheet->getTitle().' '.$cell->getCoordinate().' must not be a formula.',
                        );

                        $value = $cell->getValue();
                        if (! is_string($value)) {
                            continue;
                        }

                        $stringValues[] = $value;

                        foreach ($payloads['dangerous'] as $payload) {
                            $excelValue = str_replace(["\r\n", "\r"], "\n", $payload);
                            if ($value === $excelValue || $value === "'".$excelValue) {
                                $seen[$payload] = $cell;
                                $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), $sheet->getTitle().' '.$cell->getCoordinate());
                                $this->assertSame($excelValue, $value, $sheet->getTitle().' '.$cell->getCoordinate().' must keep the original visible text.');
                            }
                        }
                    }
                }
            }

            foreach ($payloads['dangerous'] as $payload) {
                $this->assertArrayHasKey($payload, $seen, 'XLSX is missing formula payload: '.$this->visible($payload));
                $this->assertNotContains("'".$payload, $stringValues, 'XLSX must not apostrophe-prefix: '.$this->visible($payload));
            }

            $this->assertContains('Benign purchase', $stringValues);
            $this->assertContains('Notes', $stringValues);

            $summary = $workbook->getSheetByName('Summary');
            $this->assertNotNull($summary);
            $this->assertSame(DataType::TYPE_NUMERIC, $summary->getCell('B5')->getDataType());
            $this->assertSame($payloads['started'], $summary->getCell('B5')->getValue());
            $this->assertSame('0.0%', $summary->getStyle('B10')->getNumberFormat()->getFormatCode());
            $this->assertSame('0.0', $summary->getStyle('B11')->getNumberFormat()->getFormatCode());

            $daily = $workbook->getSheetByName('Daily Activity');
            $this->assertNotNull($daily);
            $this->assertSame('yyyy-mm-dd', $daily->getStyle('A2')->getNumberFormat()->getFormatCode());

            $active = $workbook->getSheetByName('Active Workflows');
            $this->assertNotNull($active);
            $this->assertSame('yyyy-mm-dd hh:mm', $active->getStyle('D2')->getNumberFormat()->getFormatCode());
            $this->assertSame('0.0', $active->getStyle('E2')->getNumberFormat()->getFormatCode());

            $workbook->disconnectWorksheets();
        } finally {
            unlink($temporary);
        }
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

    private function submission(SubmissionStatus $status, string $title = 'Purchase request', ?int $requestTypeVersionId = null): Submission
    {
        return Submission::factory()->create([
            'status' => $status,
            'title' => $title,
            ...($requestTypeVersionId === null ? [] : ['request_type_version_id' => $requestTypeVersionId]),
        ]);
    }

    /**
     * @return array{dangerous: list<string>, started: int}
     */
    private function seedFormulaInjectionAnalytics(): array
    {
        $dangerous = [
            '=HYPERLINK("http://evil.test","x")',
            '=cmd',
            '+cmd|',
            '-cmd',
            '@SUM(1+1)',
            "\t=cmd",
            "\r=cmd",
            ' =HYPERLINK("http://evil.test","padded")',
            ' +cmd',
            ' -cmd',
            ' @SUM(1)',
            "\n=cmd",
            "\x0b=cmd",
        ];
        $named = array_slice($dangerous, 0, 8);

        $type = RequestType::factory()->create(['name' => 'Injection type']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'notes',
            'label' => 'Notes',
            'type' => RequestFieldType::ShortText,
            'position' => 0,
            'is_reportable' => true,
        ]);
        foreach ($dangerous as $index => $payload) {
            RequestFieldDefinition::factory()->for($version)->create([
                'key' => 'inject_'.$index,
                'label' => $payload,
                'type' => RequestFieldType::ShortText,
                'position' => $index + 1,
                'is_reportable' => true,
            ]);
        }
        app(PublishRequestTypeVersionAction::class)->execute($version);

        $fieldValues = ['notes' => 'Benign note'];
        foreach ($dangerous as $index => $payload) {
            $fieldValues['inject_'.$index] = $payload;
        }

        $activeTemplate = null;
        $activeStep = null;

        foreach ($named as $index => $payload) {
            $template = WorkflowTemplate::factory()->create(['name' => $payload]);
            $step = WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
                'name' => $payload,
                'timeout_hours' => 24,
                'assignee_ids' => [$this->actor->id],
            ]);
            $resolved = $this->submission(
                SubmissionStatus::Approved,
                'Purchase request',
                $index === 0 ? $version->id : null,
            );
            if ($index === 0) {
                app(StoreSubmissionFieldValuesAction::class)->execute($resolved, $fieldValues);
            }
            $instance = WorkflowInstance::factory()->create([
                'submission_id' => $resolved->id,
                'template_id' => $template->id,
                'current_step_id' => null,
                'status' => WorkflowStatus::Completed,
                'started_at' => '2026-08-10 08:00:00',
                'step_started_at' => '2026-08-10 08:00:00',
                'completed_at' => '2026-08-10 12:00:00',
            ]);
            WorkflowAction::factory()->create([
                'instance_id' => $instance->id,
                'step_id' => $step->id,
                'actor_id' => $this->actor->id,
                'action' => WorkflowActionType::Approve,
                'created_at' => '2026-08-10 12:00:00',
            ]);

            if ($index === 0) {
                $activeTemplate = $template;
                $activeStep = $step;
            }
        }

        $this->assertNotNull($activeTemplate);
        $this->assertNotNull($activeStep);
        foreach ([...$named, 'Benign purchase'] as $title) {
            $active = $this->submission(SubmissionStatus::InReview, $title);
            WorkflowInstance::factory()->create([
                'submission_id' => $active->id,
                'template_id' => $activeTemplate->id,
                'current_step_id' => $activeStep->id,
                'status' => WorkflowStatus::Active,
                'started_at' => '2026-08-15 00:00:00',
                'step_started_at' => '2026-08-15 00:00:00',
            ]);
        }

        return [
            'dangerous' => $dangerous,
            'started' => count($named) * 2 + 1,
        ];
    }

    /**
     * @return list<string>
     */
    private function csvCells(string $csv): array
    {
        $cells = [];
        $stream = fopen('php://temp', 'r+b');
        $this->assertNotFalse($stream);
        fwrite($stream, $csv);
        rewind($stream);

        while (($row = fgetcsv($stream)) !== false) {
            foreach ($row as $cell) {
                $cells[] = (string) $cell;
            }
        }

        fclose($stream);

        return $cells;
    }

    private function visible(string $payload): string
    {
        return str_replace(["\t", "\r", "\n"], ['\\t', '\\r', '\\n'], $payload);
    }
}
