<?php

namespace App\Actions\Integrations;

use App\DTOs\Integrations\ConnectorHealth;
use App\Models\IntegrationConnector;
use App\Services\Integrations\ConnectorRegistry;

final class TestIntegrationConnectorAction
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    public function execute(IntegrationConnector $connector): ConnectorHealth
    {
        $driver = $this->registry->get($connector->type);
        $health = $driver->healthCheck($connector->credentialMap());

        $connector->forceFill([
            'last_tested_at' => now(),
            'last_error' => $health->ok ? null : $connector->redact($health->message),
        ])->save();

        return $health;
    }
}
