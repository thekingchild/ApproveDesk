<?php

namespace Database\Factories;

use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowTemplateStep>
 */
class WorkflowTemplateStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'template_id' => WorkflowTemplate::factory(),
            'step_order' => 1,
            'name' => 'Approval',
            'step_type' => StepType::Sequential,
            'quorum_count' => null,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => [],
            'conditions' => null,
            'timeout_hours' => null,
            'timeout_action' => null,
            'allow_delegation' => true,
        ];
    }

    public function forTemplate(WorkflowTemplate $template, int $order): static
    {
        return $this->state(fn () => [
            'template_id' => $template->id,
            'step_order' => $order,
        ]);
    }
}
