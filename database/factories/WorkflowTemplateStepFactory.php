<?php

namespace Database\Factories;

use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Models\WorkflowTemplateVersion;
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
            'template_version_id' => null,
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

    public function configure(): static
    {
        return $this->afterMaking(function (WorkflowTemplateStep $step): void {
            if ($step->template_version_id) {
                return;
            }

            $template = $step->template_id
                ? WorkflowTemplate::query()->find($step->template_id)
                : WorkflowTemplate::factory()->create();

            if ($template === null) {
                return;
            }

            $version = $template->publishedVersion ?? $template->draftVersion ?? $template->versions()->create([
                'version' => 1,
                'status' => WorkflowTemplateVersionStatus::Draft,
                'created_by' => $template->created_by,
            ]);

            $step->template_id = $template->id;
            $step->template_version_id = $version->id;
        });
    }

    public function forTemplate(WorkflowTemplate $template, int $order): static
    {
        return $this->forVersion(
            $template->publishedVersion ?? $template->draftVersion ?? $template->versions()->create([
                'version' => 1,
                'status' => WorkflowTemplateVersionStatus::Draft,
                'created_by' => $template->created_by,
            ]),
            $order,
        );
    }

    public function forVersion(WorkflowTemplateVersion $version, int $order): static
    {
        return $this->state(fn () => [
            'template_id' => $version->workflow_template_id,
            'template_version_id' => $version->id,
            'step_order' => $order,
        ]);
    }
}
