<?php

namespace App\Contracts\Integrations;

use App\DTOs\Integrations\ConnectorHealth;
use App\DTOs\Integrations\ConnectorResult;
use App\Enums\ConnectorKey;
use App\Enums\IntegrationActionType;
use App\Models\IntegrationAction;
use App\Models\Submission;

interface Connector
{
    public function key(): ConnectorKey;

    public function label(): string;

    /** @return list<string> */
    public function credentialFields(): array;

    /** @return list<IntegrationActionType> */
    public function supportedActions(): array;

    /** @param  array<string, string>  $credentials */
    public function healthCheck(array $credentials): ConnectorHealth;

    /**
     * @param  array<string, string>  $credentials
     */
    public function execute(IntegrationAction $action, Submission $submission, array $credentials): ConnectorResult;
}
