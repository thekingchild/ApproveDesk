<?php

namespace Tests\Feature\Collaboration;

use App\Enums\TimeoutCalendar;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Collaboration\ExpectedProgress;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ExpectedProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_requester_sees_next_step_and_estimated_due_time(): void
    {
        Carbon::setTestNow('2026-08-20 09:00:00');
        $first = User::factory()->create(['name' => 'First Approver']);
        $second = User::factory()->create(['name' => 'Second Approver']);
        $template = WorkflowTemplate::factory()->default()->sequential([$first, $second])->create();
        $template->publishedVersion->steps->first()->updateQuietly([
            'timeout_hours' => 8,
            'timeout_calendar' => TimeoutCalendar::Elapsed->value,
        ]);
        $submitter = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        $progress = app(ExpectedProgress::class)->for($submission->fresh(['workflowInstance.currentStep', 'workflowInstance.template.steps', 'workflowInstance.templateVersion.steps']));

        $this->assertSame('Approval Level 2', $progress['next_step']);
        $this->assertNotNull($progress['estimated_due_at']);
        $this->assertSame('2026-08-20 17:00:00', $progress['estimated_due_at']->format('Y-m-d H:i:s'));
        $this->assertSame('elapsed', $progress['calendar']);

        Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->assertSee('Expected next step')
            ->assertSee('Approval Level 2')
            ->assertSee('Estimated due');
    }

    public function test_last_step_next_is_complete(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();
        app(WorkflowEngine::class)->initialize($submission);

        $progress = app(ExpectedProgress::class)->for($submission->fresh(['workflowInstance.currentStep', 'workflowInstance.template.steps', 'workflowInstance.templateVersion.steps']));

        $this->assertSame('Complete', $progress['next_step']);
    }
}
