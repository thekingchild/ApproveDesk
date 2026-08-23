<?php

namespace App\Actions\Integrations;

use App\Enums\IntegrationDeliveryStatus;
use App\Jobs\DeliverIntegrationAction;
use App\Models\IntegrationDelivery;

final class ReplayIntegrationDeliveryAction
{
    public function execute(IntegrationDelivery $delivery): IntegrationDelivery
    {
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
