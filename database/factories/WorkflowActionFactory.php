<?php

namespace Database\Factories;

use App\Enums\WorkflowActionType;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplateStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowAction>
 */
class WorkflowActionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instance_id' => WorkflowInstance::factory(),
            'step_id' => WorkflowTemplateStep::factory(),
            'actor_id' => User::factory(),
            'action' => WorkflowActionType::Approve,
            'comment' => null,
            'delegated_to' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'created_at' => now(),
        ];
    }
}
