<?php

namespace Database\Factories;

use App\Models\LegalHold;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalHold>
 */
class LegalHoldFactory extends Factory
{
    protected $model = LegalHold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'placed_by' => User::factory(),
            'reason' => fake()->sentence(),
            'placed_at' => now(),
            'released_at' => null,
            'released_by' => null,
        ];
    }
}
