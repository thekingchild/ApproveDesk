<?php

namespace Tests\Feature\Workflow;

use App\Actions\WorkflowTemplates\PublishWorkflowTemplateVersionAction;
use App\Enums\GroupCompletion;
use App\Enums\StepType;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTemplateVersionStatus;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\WorkflowEngine;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_response_accepts_only_one_concurrent_approval(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $later = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$first, $later])->create();
        $template->steps->first()->updateQuietly([
            'assignee_ids' => [$first->id, $second->id],
            'completion_rule' => GroupCompletion::FirstResponse->value,
        ]);
        $engine = app(WorkflowEngine::class);
        $instance = $engine->initialize(Submission::factory()->create());

        $engine->processAction($instance, $first, WorkflowActionType::Approve);

        try {
            $engine->processAction($instance->fresh(), $second, WorkflowActionType::Approve);
            $this->fail('The second concurrent first-response approval must be rejected.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('not an assignee', $exception->getMessage());
        }

        $this->assertSame(WorkflowStatus::Active, $instance->fresh()->status);
        $this->assertSame(2, $instance->fresh()->currentStep?->step_order);
        $this->assertSame(1, WorkflowAction::query()->where('instance_id', $instance->id)->where('action', WorkflowActionType::Approve)->count());
    }

    public function test_parallel_group_records_each_decision_and_advances_once(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'step_type' => StepType::Parallel,
            'assignee_ids' => [$a->id, $b->id],
            'completion_rule' => GroupCompletion::All->value,
        ]);
        $engine = app(WorkflowEngine::class);
        $instance = $engine->initialize(Submission::factory()->create());

        $engine->processAction($instance, $a, WorkflowActionType::Approve);
        $engine->processAction($instance->fresh(), $b, WorkflowActionType::Approve);

        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);
        $this->assertSame(2, WorkflowAction::query()->where('instance_id', $instance->id)->where('action', WorkflowActionType::Approve)->count());
    }

    public function test_publishing_the_same_version_twice_loses_the_race(): void
    {
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $template = WorkflowTemplate::factory()->sequential([$approver])->create();
        $draft = $template->versions()->create([
            'version' => 2,
            'status' => WorkflowTemplateVersionStatus::Draft,
            'created_by' => $admin->id,
            'change_summary' => 'Race draft.',
        ]);
        $template->publishedVersion->steps->each(function (WorkflowTemplateStep $step) use ($draft, $template): void {
            $draft->steps()->create([
                'template_id' => $template->id,
                'step_order' => $step->step_order,
                'name' => $step->name.' v2',
                'step_type' => $step->step_type,
                'assignee_type' => $step->assignee_type,
                'assignee_ids' => $step->assignee_ids,
                'allow_delegation' => $step->allow_delegation,
            ]);
        });

        $publisher = app(PublishWorkflowTemplateVersionAction::class);
        $published = $publisher->execute($draft->fresh('steps'), 'First publisher wins.');
        $this->assertSame(WorkflowTemplateVersionStatus::Published, $published->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only draft workflow template versions can be published.');
        $publisher->execute($draft->fresh('steps'), 'Second publisher.');
    }

    public function test_same_actor_cannot_double_spend_a_decision_after_the_lock_releases(): void
    {
        $approver = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $engine = app(WorkflowEngine::class);
        $instance = $engine->initialize(Submission::factory()->create());

        $engine->processAction($instance, $approver, WorkflowActionType::Approve);

        $this->expectException(WorkflowException::class);
        $engine->processAction($instance->fresh(), $approver, WorkflowActionType::Approve);
    }
}
