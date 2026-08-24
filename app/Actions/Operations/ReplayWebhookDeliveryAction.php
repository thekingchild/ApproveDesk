<?php

namespace App\Actions\Operations;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use InvalidArgumentException;

final class ReplayWebhookDeliveryAction
{
    public function execute(WebhookDelivery $delivery): WebhookDelivery
    {
        if ($delivery->status === WebhookDeliveryStatus::Delivered) {
            throw new InvalidArgumentException('A delivered webhook cannot be replayed.');
        }

        if ($delivery->status !== WebhookDeliveryStatus::Failed) {
            throw new InvalidArgumentException('Only failed webhooks can be replayed.');
        }

        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Pending,
            'attempts' => 0,
            'last_error' => null,
            'next_attempt_at' => null,
            'delivered_at' => null,
        ])->save();

        DeliverWebhook::dispatch($delivery->id);

        return $delivery->fresh() ?? $delivery;
    }
}
