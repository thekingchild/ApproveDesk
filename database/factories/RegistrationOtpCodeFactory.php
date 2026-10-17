<?php

namespace Database\Factories;

use App\Models\RegistrationOtpCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationOtpCode>
 */
class RegistrationOtpCodeFactory extends Factory
{
    protected $model = RegistrationOtpCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->safeEmail(),
            'code_hash' => hash('sha256', '123456'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'consumed_at' => null,
        ];
    }
}
