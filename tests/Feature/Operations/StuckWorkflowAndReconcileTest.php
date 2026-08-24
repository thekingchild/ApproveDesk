<?php

namespace Tests\Feature\Operations;

use App\Enums\SubmissionStatus;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Services\Operations\OperationsReconciler;
use App\Services\Operations\StuckWorkflowDiagnostics;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StuckWorkflowAndReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_active_instance_without_a_step_is_stuck(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        $instance = app(WorkflowEngine::class)->initialize($submission);
        $instance->forceFill([
            'current_step_id' => null,
            'status' => WorkflowStatus::Active,
            'step_started_at' => now()->subDays(3),
        ])->save();

        $stuck = app(StuckWorkflowDiagnostics::class)->find();

        $this->assertTrue($stuck->contains(fn ($item) => $item->instanceId === $instance->id));
        $this->artisan('ops:diagnose-stuck')->assertSuccessful()->expectsOutputToContain($submission->reference_number);
    }

    public function test_recent_active_instance_is_not_stuck(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        $this->assertSame(0, app(StuckWorkflowDiagnostics::class)->count());
    }

    public function test_reconcile_reports_submitted_request_without_an_instance(): void
    {
        Submission::factory()->create(['status' => SubmissionStatus::Pending]);

        $report = app(OperationsReconciler::class)->run();

        $this->assertFalse($report->ok());
        $this->assertSame(1, $report->submittedWithoutInstance);
        $this->artisan('ops:reconcile')->assertFailed();
    }

    public function test_reconcile_is_clean_when_engine_created_the_instance(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        $this->assertTrue(app(OperationsReconciler::class)->run()->ok());
        $this->artisan('ops:reconcile')->assertSuccessful();
    }

    public function test_reconcile_does_not_create_or_delete_instances(): void
    {
        Submission::factory()->create(['status' => SubmissionStatus::Pending]);
        $before = WorkflowInstance::query()->count();

        app(OperationsReconciler::class)->run();

        $this->assertSame($before, WorkflowInstance::query()->count());
    }
}
