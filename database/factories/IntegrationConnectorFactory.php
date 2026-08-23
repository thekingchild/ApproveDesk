<?php

namespace Database\Factories;

use App\Enums\ConnectorKey;
use App\Models\IntegrationConnector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationConnector>
 */
class IntegrationConnectorFactory extends Factory
{
    protected $model = IntegrationConnector::class;

    public function definition(): array
    {
        return [
            'type' => ConnectorKey::Http,
            'name' => 'HTTP '.fake()->unique()->word(),
            'is_enabled' => true,
            'credentials' => [
                'url' => 'https://hooks.example.test/approvedesk',
                'secret' => 'test-secret',
            ],
            'min_interval_seconds' => 1,
        ];
    }
}
