<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowTemplate>
 */
class WorkflowTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'submission_types' => null,
            'is_default' => false,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    /**
     * Sequential chain of steps, each assigned to the given approvers
     * (one user per step, in order).
     *
     * @param  array<int, User|int>  $approvers
     */
    public function sequential(array $approvers): static
    {
        return $this->afterCreating(function (WorkflowTemplate $template) use ($approvers) {
            foreach (array_values($approvers) as $index => $approver) {
                WorkflowTemplateStep::factory()->forTemplate($template, $index + 1)->create([
                    'name' => 'Approval Level '.($index + 1),
                    'assignee_type' => 'user',
                    'assignee_ids' => [$approver instanceof User ? $approver->id : $approver],
                ]);
            }
        });
    }
}
