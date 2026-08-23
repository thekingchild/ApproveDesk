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

final class SharePointConnector implements Connector
{
    public function __construct(private readonly ConnectorHttpClient $http) {}

    public function key(): ConnectorKey
    {
        return ConnectorKey::SharePoint;
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
        if (($credentials['access_token'] ?? '') === '' || ($credentials['site_url'] ?? '') === '') {
            return new ConnectorHealth(false, 'An access token and site URL are required.');
        }

        return new ConnectorHealth(true, 'SharePoint credentials are present.');
    }

    public function execute(IntegrationAction $action, Submission $submission, array $credentials): ConnectorResult
    {
        $token = $credentials['access_token'] ?? '';
        $site = rtrim((string) ($credentials['site_url'] ?? ''), '/');

        if ($token === '' || $site === '') {
            return new ConnectorResult(false, error: 'SharePoint access token or site URL is missing.');
        }

        $payload = $this->http->submissionPayload($submission);

        return $this->http->post(
            $site.'/_api/web/lists/getbytitle(\'Documents\')/rootfolder/files/add',
            $payload,
            ['Authorization' => 'Bearer '.$token],
        );
    }
}
