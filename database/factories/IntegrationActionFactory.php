<?php

namespace Database\Factories;

use App\Enums\IntegrationActionType;
use App\Models\IntegrationAction;
use App\Models\IntegrationConnector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationAction>
 */
class IntegrationActionFactory extends Factory
{
    protected $model = IntegrationAction::class;

    public function definition(): array
    {
        return [
            'connector_id' => IntegrationConnector::factory(),
            'request_type_id' => null,
            'action' => IntegrationActionType::InvokeWebhook,
            'name' => 'Notify ERP',
            'configuration' => [],
            'is_enabled' => true,
        ];
    }
}
