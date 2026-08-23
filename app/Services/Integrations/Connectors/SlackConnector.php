<?php

namespace App\Services\Integrations\Connectors;

use App\Enums\ConnectorKey;

final class SlackConnector extends WebhookStyleConnector
{
    public function key(): ConnectorKey
    {
        return ConnectorKey::Slack;
    }
}
