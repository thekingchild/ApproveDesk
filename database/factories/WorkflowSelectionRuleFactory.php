<?php

namespace Database\Factories;

use App\Models\RequestType;
use App\Models\WorkflowSelectionRule;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowSelectionRule>
 */
class WorkflowSelectionRuleFactory extends Factory
{
    protected $model = WorkflowSelectionRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_type_id' => RequestType::factory(),
            'workflow_template_id' => WorkflowTemplate::factory(),
            'name' => fake()->words(3, true),
            'priority' => 1,
            'is_active' => true,
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'high']]],
        ];
    }
}
