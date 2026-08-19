<?php

namespace Tests\Unit\Services\WorkflowTemplates;

use App\Enums\AssigneeType;
use App\Enums\GroupCompletion;
use App\Enums\RequestTypeVersionStatus;
use App\Enums\StepType;
use App\Enums\TimeoutAction;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\User;
use App\Models\WorkflowSelectionRule;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\Workflow\WorkflowTemplateSelector;
use App\Services\WorkflowTemplates\WorkflowGraphValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkflowGraphPropertyTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_linear_graphs_validate(): void
    {
        $approver = User::factory()->create();

        foreach (range(1, 5) as $length) {
            $steps = collect(range(1, $length))->map(fn (int $order): WorkflowTemplateStep => $this->step([
                'step_order' => $order,
                'name' => 'Step '.$order,
                'step_type' => StepType::Sequential,
                'assignee_type' => AssigneeType::User,
                'assignee_ids' => [$approver->id],
            ]));

            app(WorkflowGraphValidator::class)->validate($steps);
            $this->assertSame($length, $steps->count());
        }
    }

    public function test_generated_invalid_graphs_are_rejected(): void
    {
        $approver = User::factory()->create();
        $validator = app(WorkflowGraphValidator::class);

        $this->assertInvalid($validator, collect());

        $this->assertInvalid($validator, collect([
            $this->step(['step_order' => 1, 'assignee_ids' => []]),
        ]));

        $this->assertInvalid($validator, collect([
            $this->step([
                'step_order' => 1,
                'step_type' => StepType::Quorum,
                'completion_rule' => GroupCompletion::Quorum,
                'quorum_count' => 4,
                'assignee_ids' => [$approver->id],
            ]),
        ]));

        $this->assertInvalid($validator, collect([
            $this->step([
                'step_order' => 1,
                'step_type' => StepType::Conditional,
                'assignee_type' => AssigneeType::User,
                'assignee_ids' => [],
                'conditions' => ['true_step' => 1, 'false_step' => null],
            ]),
        ]));

        $this->assertInvalid($validator, collect([
            $this->step(['step_order' => 1, 'assignee_ids' => [$approver->id], 'timeout_hours' => 2]),
        ]));

        $this->assertInvalid($validator, collect([
            $this->step([
                'step_order' => 1,
                'assignee_ids' => [$approver->id],
                'timeout_hours' => 2,
                'timeout_action' => TimeoutAction::Escalate,
            ]),
        ]));

        $this->assertInvalid($validator, collect([
            $this->step([
                'step_order' => 1,
                'step_type' => StepType::Conditional,
                'assignee_ids' => [],
                'conditions' => [
                    'true_step' => 3,
                    'false_step' => 3,
                    'match' => 'all',
                    'rules' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'high']],
                ],
            ]),
            $this->step(['step_order' => 2, 'name' => 'Unreachable', 'assignee_ids' => [$approver->id]]),
            $this->step(['step_order' => 3, 'name' => 'Later', 'assignee_ids' => [$approver->id]]),
        ]));

        $this->assertInvalid($validator, collect([
            $this->step([
                'step_order' => 1,
                'assignee_type' => AssigneeType::FormField,
                'assignee_ids' => [],
                'assignee_config' => [],
            ]),
        ]));
    }

    public function test_route_selection_is_deterministic_for_the_same_sample(): void
    {
        $approver = User::factory()->create();
        $high = WorkflowTemplate::factory()->sequential([$approver])->create(['name' => 'High']);
        $assigned = WorkflowTemplate::factory()->sequential([$approver])->create(['name' => 'Assigned']);
        WorkflowTemplate::factory()->default()->sequential([$approver])->create(['name' => 'Default']);
        $type = RequestType::factory()->create(['workflow_template_id' => $assigned->id]);
        RequestTypeVersion::factory()->for($type)->create(['status' => RequestTypeVersionStatus::Published]);

        WorkflowSelectionRule::query()->create([
            'request_type_id' => $type->id,
            'workflow_template_id' => $high->id,
            'name' => 'High amount',
            'priority' => 1,
            'is_active' => true,
            'conditions' => [
                'match' => 'all',
                'rules' => [['field' => 'metadata.amount', 'operator' => 'gte', 'value' => 10000]],
            ],
        ]);

        $selector = app(WorkflowTemplateSelector::class);
        $first = $selector->explain($type->fresh(['workflowTemplate', 'selectionRules.template', 'publishedVersion']), [
            'metadata' => ['amount' => 15000],
        ]);
        $second = $selector->explain($type->fresh(['workflowTemplate', 'selectionRules.template', 'publishedVersion']), [
            'metadata' => ['amount' => 15000],
        ]);

        $this->assertSame($first, $second);
        $this->assertTrue($first[0]['matched']);
        $this->assertSame('High', $first[0]['template']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function step(array $attributes): WorkflowTemplateStep
    {
        $step = new WorkflowTemplateStep(array_merge([
            'name' => 'Step',
            'step_order' => 1,
            'step_type' => StepType::Sequential,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => [],
            'allow_delegation' => true,
            'conditions' => null,
            'timeout_hours' => null,
            'timeout_action' => null,
        ], $attributes));
        $step->syncOriginal();

        return $step;
    }

    private function assertInvalid(WorkflowGraphValidator $validator, Collection $steps): void
    {
        try {
            $validator->validate($steps);
            $this->fail('Generated invalid graph was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
