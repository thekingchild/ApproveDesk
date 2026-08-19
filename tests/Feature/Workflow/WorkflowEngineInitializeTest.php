<?php

namespace Tests\Feature\Workflow;

use App\Enums\SubmissionStatus;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowEngineInitializeTest extends TestCase
{
    use RefreshDatabase;

    public function test_initialize_creates_active_instance_on_first_step(): void
    {
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();

        $instance = app(WorkflowEngine::class)->initialize($submission);

        $this->assertSame(WorkflowStatus::Active, $instance->status);
        $this->assertSame($template->steps->first()->id, $instance->current_step_id);
        $this->assertTrue($submission->refresh()->status === SubmissionStatus::InReview);
    }

    public function test_initialize_prefers_request_type_assignment_over_default(): void
    {
        $approver = User::factory()->create();
        $assigned = WorkflowTemplate::factory()->sequential([$approver])->create([
            'submission_types' => ['memo'],
        ]);
        $default = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $requestType = RequestTypeVersion::general()->requestType;
        $requestType->update(['workflow_template_id' => $assigned->id]);

        $memo = Submission::factory()->create(['type' => 'memo']);
        $instance = app(WorkflowEngine::class)->initialize($memo);

        $this->assertSame($assigned->id, $instance->template_id);

        $requestType->update(['workflow_template_id' => null]);
        $note = Submission::factory()->create(['type' => 'note']);
        $instance = app(WorkflowEngine::class)->initialize($note);

        $this->assertSame($default->id, $instance->template_id);
    }

    public function test_initialize_ignores_legacy_submission_type_coverage(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->sequential([$approver])->create([
            'submission_types' => ['memo'],
        ]);
        $default = WorkflowTemplate::factory()->default()->sequential([$approver])->create();

        $memo = Submission::factory()->create(['type' => 'memo']);
        $instance = app(WorkflowEngine::class)->initialize($memo);

        $this->assertSame($default->id, $instance->template_id);
    }

    public function test_initialize_ignores_inactive_templates(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->sequential([$approver])->create(['is_active' => false]);
        $submission = Submission::factory()->create();

        $this->expectException(WorkflowException::class);

        app(WorkflowEngine::class)->initialize($submission);
    }

    public function test_initialize_rejects_template_without_steps(): void
    {
        WorkflowTemplate::factory()->default()->create();
        $submission = Submission::factory()->create();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('has no steps');

        app(WorkflowEngine::class)->initialize($submission);
    }

    public function test_only_one_instance_exists_per_submission(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();

        app(WorkflowEngine::class)->initialize($submission);

        $this->expectException(QueryException::class);

        app(WorkflowEngine::class)->initialize($submission);
    }
}
