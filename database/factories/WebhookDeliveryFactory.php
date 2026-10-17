<?php

namespace Database\Factories;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'endpoint_id' => WebhookEndpoint::factory(),
            'event_id' => fake()->uuid(),
            'event' => 'submission.created',
            'payload' => ['event' => 'submission.created'],
            'status' => WebhookDeliveryStatus::Pending,
            'attempts' => 0,
            'response_status' => null,
            'response_body' => null,
            'last_error' => null,
            'delivered_at' => null,
            'next_attempt_at' => null,
        ];
    }
}
