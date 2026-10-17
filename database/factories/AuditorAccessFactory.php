<?php

namespace Database\Factories;

use App\Models\AuditorAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditorAccess>
 */
class AuditorAccessFactory extends Factory
{
    protected $model = AuditorAccess::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'granted_by' => User::factory(),
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'revoked_at' => null,
            'revoked_by' => null,
            'reason' => fake()->sentence(),
            'scopes' => null,
        ];
    }
}
