<?php

namespace App\Actions\Operations;

use App\Actions\Integrations\ReplayIntegrationDeliveryAction;
use App\Models\IntegrationDelivery;
use App\Models\NotificationDelivery;
use App\Models\WebhookDelivery;
use InvalidArgumentException;

final class ReplayDeadLetterAction
{
    public function __construct(
        private readonly ReplayWebhookDeliveryAction $webhooks,
        private readonly ReplayIntegrationDeliveryAction $integrations,
        private readonly ReplayNotificationDeliveryAction $notifications,
        private readonly ReplayFailedJobAction $jobs,
    ) {}

    public function execute(string $type, string $id): void
    {
        match ($type) {
            'webhook' => $this->webhooks->execute(WebhookDelivery::query()->findOrFail((int) $id)),
            'integration' => $this->integrations->execute(IntegrationDelivery::query()->findOrFail((int) $id)),
            'notification' => $this->notifications->execute(NotificationDelivery::query()->findOrFail((int) $id)),
            'job' => $this->jobs->execute($id),
            default => throw new InvalidArgumentException('Unknown dead-letter type.'),
        };
    }
}
