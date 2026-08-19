<?php

namespace Database\Factories;

use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\Submission;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowInstance>
 */
class WorkflowInstanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'template_id' => WorkflowTemplate::factory(),
            'template_version_id' => null,
            'current_step_id' => null,
            'status' => WorkflowStatus::Active,
            'started_at' => now(),
            'step_started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (WorkflowInstance $instance): void {
            if ($instance->template_version_id) {
                return;
            }

            $template = $instance->template_id
                ? WorkflowTemplate::query()->find($instance->template_id)
                : null;

            if ($template === null) {
                return;
            }

            $version = $template->publishedVersion ?? $template->draftVersion ?? $template->versions()->create([
                'version' => 1,
                'status' => WorkflowTemplateVersionStatus::Draft,
                'created_by' => $template->created_by,
            ]);

            $instance->template_version_id = $version->id;
        });
    }
}
