<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.fake()->uuid(),
            'endpoint_hash' => fn (array $attributes) => hash('sha256', $attributes['endpoint']),
            'public_key' => fake()->sha256(),
            'auth_token' => fake()->sha1(),
            'content_encoding' => 'aes128gcm',
        ];
    }
}
