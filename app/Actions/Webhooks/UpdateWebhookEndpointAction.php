<?php

namespace App\Actions\Webhooks;

use App\DTOs\WebhookEndpointData;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateWebhookEndpointAction
{
    /** @return string|null A replacement secret when one was generated. */
    public function execute(WebhookEndpoint $endpoint, WebhookEndpointData $data, bool $rotateSecret = false): ?string
    {
        return DB::transaction(function () use ($endpoint, $data, $rotateSecret): ?string {
            /** @var WebhookEndpoint $endpoint */
            $endpoint = WebhookEndpoint::query()->whereKey($endpoint->getKey())->lockForUpdate()->firstOrFail();
            $secret = $rotateSecret ? Str::random(64) : null;

            $endpoint->update([
                'name' => $data->name,
                'url' => $data->url,
                'events' => $data->events,
                'is_active' => $data->isActive,
                ...($secret === null ? [] : ['secret' => $secret]),
            ]);

            return $secret;
        });
    }
}
