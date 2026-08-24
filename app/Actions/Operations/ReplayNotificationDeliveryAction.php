<?php

namespace App\Actions\Operations;

use App\Enums\NotificationDeliveryStatus;
use App\Jobs\RetryNotificationDeliveries;
use App\Models\NotificationDelivery;
use InvalidArgumentException;

final class ReplayNotificationDeliveryAction
{
    public function execute(NotificationDelivery $delivery): NotificationDelivery
    {
        if ($delivery->status !== NotificationDeliveryStatus::Failed) {
            throw new InvalidArgumentException('Only failed notices can be replayed.');
        }

        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Failed,
            'attempts' => 0,
            'last_error' => $delivery->last_error,
            'next_retry_at' => now(),
        ])->save();

        RetryNotificationDeliveries::dispatch();

        return $delivery->fresh() ?? $delivery;
    }
}
