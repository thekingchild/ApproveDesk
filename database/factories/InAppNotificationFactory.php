<?php

namespace Database\Factories;

use App\Models\InAppNotification;
use App\Models\User;
use App\Notifications\ActionRequiredNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InAppNotification>
 */
class InAppNotificationFactory extends Factory
{
    protected $model = InAppNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->uuid(),
            'type' => ActionRequiredNotification::class,
            'notifiable_type' => (new User)->getMorphClass(),
            'notifiable_id' => User::factory(),
            'data' => ['kind' => 'action_required', 'title' => fake()->sentence(3)],
            'read_at' => null,
        ];
    }
}
