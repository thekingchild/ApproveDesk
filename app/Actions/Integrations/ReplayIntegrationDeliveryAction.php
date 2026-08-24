<?php

namespace App\Actions\Integrations;

use App\Enums\IntegrationDeliveryStatus;
use App\Jobs\DeliverIntegrationAction;
use App\Models\IntegrationDelivery;
use InvalidArgumentException;

final class ReplayIntegrationDeliveryAction
{
    public function execute(IntegrationDelivery $delivery): IntegrationDelivery
    {
        if ($delivery->status === IntegrationDeliveryStatus::Delivered) {
            throw new InvalidArgumentException('A delivered integration action cannot be replayed.');
        }

        if ($delivery->status !== IntegrationDeliveryStatus::DeadLetter) {
            throw new InvalidArgumentException('Only dead-letter deliveries can be replayed.');
        }

        $delivery->forceFill([
            'status' => IntegrationDeliveryStatus::Pending,
            'attempts' => 0,
            'last_error' => null,
            'next_attempt_at' => null,
            'delivered_at' => null,
        ])->save();

        DeliverIntegrationAction::dispatch($delivery->id);

        return $delivery->fresh();
    }
}
