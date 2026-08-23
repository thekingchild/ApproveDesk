<?php

namespace App\Services\Integrations\Connectors;

use App\Contracts\Integrations\Connector;
use App\DTOs\Integrations\ConnectorHealth;
use App\DTOs\Integrations\ConnectorResult;
use App\Enums\ConnectorKey;
use App\Models\IntegrationAction;
use App\Models\Submission;
use App\Services\Integrations\ConnectorHttpClient;

abstract class WebhookStyleConnector implements Connector
{
    public function __construct(protected readonly ConnectorHttpClient $http) {}

    abstract public function key(): ConnectorKey;

    public function label(): string
    {
        return $this->key()->label();
    }

    public function credentialFields(): array
    {
        return $this->key()->credentialFields();
    }

    public function supportedActions(): array
    {
        return $this->key()->defaultActions();
    }

    public function healthCheck(array $credentials): ConnectorHealth
    {
        $url = $credentials['webhook_url'] ?? $credentials['url'] ?? '';

        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return new ConnectorHealth(false, 'An HTTPS destination URL is required.');
        }

        return new ConnectorHealth(true, 'Destination URL looks valid. A live ping is sent when you test.');
    }

    public function execute(IntegrationAction $action, Submission $submission, array $credentials): ConnectorResult
    {
        $url = $credentials['webhook_url'] ?? $credentials['url'] ?? '';

        if ($url === '') {
            return new ConnectorResult(false, error: 'Connector is missing a destination URL.');
        }

        $payload = $this->http->submissionPayload($submission);
        $payload['action'] = $action->action->value;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = ['Content-Type' => 'application/json'];

        if (($credentials['secret'] ?? '') !== '') {
            $headers = [...$headers, ...$this->http->sign($credentials['secret'], $body)];
        }

        return $this->http->post($url, $body, $headers);
    }
}
