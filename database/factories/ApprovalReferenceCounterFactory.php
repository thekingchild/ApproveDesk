<?php

namespace Database\Factories;

use App\Models\ApprovalReferenceCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalReferenceCounter>
 */
class ApprovalReferenceCounterFactory extends Factory
{
    protected $model = ApprovalReferenceCounter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => '5:'.fake()->unique()->bothify('SUB-####-'),
            'current_value' => 0,
        ];
    }
}
