<?php

namespace App\Services\Integrations\Connectors;

use App\Contracts\Integrations\Connector;
use App\DTOs\Integrations\ConnectorHealth;
use App\DTOs\Integrations\ConnectorResult;
use App\Enums\ConnectorKey;
use App\Enums\IntegrationActionType;
use App\Models\IntegrationAction;
use App\Models\Submission;
use App\Services\Integrations\ConnectorHttpClient;

final class MailConnector implements Connector
{
    public function __construct(private readonly ConnectorHttpClient $http) {}

    public function key(): ConnectorKey
    {
        return ConnectorKey::Mail;
    }

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
        return [IntegrationActionType::SendEmail];
    }

    public function healthCheck(array $credentials): ConnectorHealth
    {
        if (($credentials['access_token'] ?? '') === '') {
            return new ConnectorHealth(false, 'An access token is required.');
        }

        return new ConnectorHealth(true, 'Mail credentials are present.');
    }

    public function execute(IntegrationAction $action, Submission $submission, array $credentials): ConnectorResult
    {
        $token = $credentials['access_token'] ?? '';

        if ($token === '') {
            return new ConnectorResult(false, error: 'Mail access token is missing.');
        }

        $to = (string) ($action->configuration['to'] ?? $submission->submitter?->email ?? '');
        $payload = [
            ...$this->http->submissionPayload($submission),
            'from' => $credentials['from'] ?? '',
            'to' => $to,
            'subject' => 'Approved: '.($submission->title ?? $submission->reference_number),
        ];

        return $this->http->post(
            'https://graph.microsoft.com/v1.0/me/sendMail',
            $payload,
            ['Authorization' => 'Bearer '.$token],
        );
    }
}
