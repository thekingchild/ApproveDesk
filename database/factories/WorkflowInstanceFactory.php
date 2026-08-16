<?php

namespace Database\Factories;

use App\Enums\WorkflowStatus;
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
            'current_step_id' => null,
            'status' => WorkflowStatus::Active,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }
}
