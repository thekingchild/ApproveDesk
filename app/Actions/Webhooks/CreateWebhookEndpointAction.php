<?php

namespace App\Actions\Webhooks;

use App\DTOs\WebhookEndpointData;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateWebhookEndpointAction
{
    /** @return array{endpoint: WebhookEndpoint, secret: string} */
    public function execute(WebhookEndpointData $data, User $creator): array
    {
        $secret = Str::random(64);

        $endpoint = DB::transaction(fn (): WebhookEndpoint => WebhookEndpoint::create([
            'name' => $data->name,
            'url' => $data->url,
            'secret' => $secret,
            'events' => $data->events,
            'is_active' => $data->isActive,
            'created_by' => $creator->id,
        ]));

        return compact('endpoint', 'secret');
    }
}
