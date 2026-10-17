<?php

namespace Database\Factories;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoginEvent>
 */
class LoginEventFactory extends Factory
{
    protected $model = LoginEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'fingerprint' => hash('sha256', fake()->uuid()),
            'was_new_device' => false,
            'notified' => false,
        ];
    }
}
