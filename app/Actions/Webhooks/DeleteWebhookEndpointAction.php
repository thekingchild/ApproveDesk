<?php

namespace App\Actions\Webhooks;

use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;

final class DeleteWebhookEndpointAction
{
    public function execute(WebhookEndpoint $endpoint): void
    {
        DB::transaction(function () use ($endpoint): void {
            WebhookEndpoint::query()->whereKey($endpoint->getKey())->lockForUpdate()->firstOrFail()->delete();
        });
    }
}
