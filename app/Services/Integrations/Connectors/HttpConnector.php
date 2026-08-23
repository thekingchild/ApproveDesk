<?php

namespace App\Services\Integrations\Connectors;

use App\Enums\ConnectorKey;

final class HttpConnector extends WebhookStyleConnector
{
    public function key(): ConnectorKey
    {
        return ConnectorKey::Http;
    }
}
