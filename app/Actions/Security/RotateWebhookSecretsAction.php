<?php

namespace App\Actions\Security;

use App\Models\WebhookEndpoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class RotateWebhookSecretsAction
{
    /**
     * @return Collection<int, string> endpoint id => new secret
     */
    public function execute(?WebhookEndpoint $endpoint = null): Collection
    {
        $query = WebhookEndpoint::query();

        if ($endpoint !== null) {
            $query->whereKey($endpoint->id);
        }

        $rotated = collect();

        $query->get()->each(function (WebhookEndpoint $item) use ($rotated): void {
            $secret = Str::random(64);
            $item->forceFill(['secret' => $secret])->save();
            $rotated->put($item->id, $secret);
        });

        return $rotated;
    }
}
