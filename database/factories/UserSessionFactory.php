<?php

namespace Database\Factories;

use App\Models\UserSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserSession>
 */
class UserSessionFactory extends Factory
{
    protected $model = UserSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->sha1(),
            'user_id' => null,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ];
    }
}
