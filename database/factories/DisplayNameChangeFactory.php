<?php

namespace Database\Factories;

use App\Enums\DisplayNameChangeStatus;
use App\Models\DisplayNameChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DisplayNameChange>
 */
class DisplayNameChangeFactory extends Factory
{
    protected $model = DisplayNameChange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pending_user_id' => fn (array $attributes) => $attributes['user_id'],
            'proposed_name' => fake()->name(),
            'normalized_name' => fn (array $attributes) => mb_strtolower($attributes['proposed_name']),
            'status' => DisplayNameChangeStatus::Pending,
            'decided_by' => null,
            'decided_at' => null,
            'reason' => null,
        ];
    }
}
