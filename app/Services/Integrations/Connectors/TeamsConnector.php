<?php

namespace App\Services\Integrations\Connectors;

use App\Enums\ConnectorKey;

final class TeamsConnector extends WebhookStyleConnector
{
    public function key(): ConnectorKey
    {
        return ConnectorKey::Teams;
    }
}
