<?php

namespace Database\Factories;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'action_required',
            'channel' => 'mail',
            'status' => NotificationDeliveryStatus::Queued,
            'attempts' => 0,
            'last_error' => null,
            'payload' => null,
            'sent_at' => null,
            'next_retry_at' => null,
            'submission_id' => null,
        ];
    }
}
