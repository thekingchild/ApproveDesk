<?php

namespace Database\Factories;

use App\Models\ApiIdempotencyKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiIdempotencyKey>
 */
class ApiIdempotencyKeyFactory extends Factory
{
    protected $model = ApiIdempotencyKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => fake()->uuid(),
            'method' => 'POST',
            'path' => 'api/v1/submissions',
            'request_hash' => hash('sha256', fake()->uuid()),
            'status_code' => null,
            'response_body' => null,
        ];
    }
}
