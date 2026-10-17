<?php

namespace Database\Factories;

use App\Models\AccountRecoveryEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountRecoveryEvent>
 */
class AccountRecoveryEventFactory extends Factory
{
    protected $model = AccountRecoveryEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'target_id' => User::factory(),
            'action' => fake()->word(),
            'metadata' => null,
        ];
    }
}
