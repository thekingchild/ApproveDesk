<?php

namespace Database\Factories;

use App\Enums\IntegrationDeliveryStatus;
use App\Models\IntegrationAction;
use App\Models\IntegrationDelivery;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationDelivery>
 */
class IntegrationDeliveryFactory extends Factory
{
    protected $model = IntegrationDelivery::class;

    public function definition(): array
    {
        return [
            'integration_action_id' => IntegrationAction::factory(),
            'submission_id' => Submission::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => IntegrationDeliveryStatus::Pending,
            'attempts' => 0,
            'payload' => [],
        ];
    }
}
