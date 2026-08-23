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

final class GoogleDriveConnector implements Connector
{
    public function __construct(private readonly ConnectorHttpClient $http) {}

    public function key(): ConnectorKey
    {
        return ConnectorKey::GoogleDrive;
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
        return [IntegrationActionType::UploadFile];
    }

    public function healthCheck(array $credentials): ConnectorHealth
    {
        if (($credentials['access_token'] ?? '') === '') {
            return new ConnectorHealth(false, 'An access token is required.');
        }

        return new ConnectorHealth(true, 'Token is present. Test connection will call Google Drive.');
    }

    public function execute(IntegrationAction $action, Submission $submission, array $credentials): ConnectorResult
    {
        $token = $credentials['access_token'] ?? '';

        if ($token === '') {
            return new ConnectorResult(false, error: 'Google Drive access token is missing.');
        }

        $folder = $credentials['folder_id'] ?? ($action->configuration['folder_id'] ?? 'root');
        $payload = $this->http->submissionPayload($submission);
        $payload['folder_id'] = $folder;

        return $this->http->post(
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
            $payload,
            ['Authorization' => 'Bearer '.$token],
        );
    }
}
