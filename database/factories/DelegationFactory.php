<?php

namespace Database\Factories;

use App\Models\Delegation;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Delegation> */
class DelegationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instance_id' => WorkflowInstance::factory(),
            'step_id' => fn (array $attributes) => WorkflowInstance::query()
                ->whereKey($attributes['instance_id'])
                ->firstOrFail()
                ->current_step_id,
            'delegator_id' => User::factory(),
            'delegate_id' => User::factory(),
            'starts_at' => now(),
            'expires_at' => now()->addDay(),
        ];
    }
}
