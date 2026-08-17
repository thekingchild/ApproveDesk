<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookEndpoint> */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' integration',
            'url' => 'https://'.fake()->domainName().'/webhooks/approvedesk',
            'secret' => fake()->sha256(),
            'events' => [WebhookEvent::WorkflowCompleted->value],
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
