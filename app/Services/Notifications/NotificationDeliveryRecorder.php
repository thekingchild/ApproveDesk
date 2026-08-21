<?php

namespace App\Services\Notifications;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Collaboration\QuietHours;

final class NotificationDeliveryRecorder
{
    public function __construct(private readonly QuietHours $quietHours) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function start(User $user, string $kind, string $channel, array $payload = []): NotificationDelivery
    {
        $deferred = $channel !== 'database' && $channel !== 'mail' && $this->quietHours->isQuiet();

        return NotificationDelivery::query()->create([
            'user_id' => $user->id,
            'kind' => $kind,
            'channel' => $channel,
            'status' => $deferred ? NotificationDeliveryStatus::Deferred : NotificationDeliveryStatus::Queued,
            'attempts' => 0,
            'payload' => $payload,
            'next_retry_at' => $deferred ? $this->quietHours->nextOpenAt() : null,
        ]);
    }

    public function markSent(NotificationDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Sent,
            'attempts' => $delivery->attempts + 1,
            'last_error' => null,
            'sent_at' => now(),
            'next_retry_at' => null,
        ])->save();
    }

    public function markSkipped(NotificationDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Skipped,
            'attempts' => $delivery->attempts + 1,
            'last_error' => $reason,
            'next_retry_at' => null,
        ])->save();
    }

    public function markFailed(NotificationDelivery $delivery, string $error, bool $retry): void
    {
        $attempts = $delivery->attempts + 1;
        $critical = $delivery->kind === 'action_required';
        $shouldRetry = $retry && $critical && $attempts < 3;

        $delivery->forceFill([
            'status' => $shouldRetry ? NotificationDeliveryStatus::Failed : NotificationDeliveryStatus::Failed,
            'attempts' => $attempts,
            'last_error' => $error,
            'next_retry_at' => $shouldRetry ? now()->addMinutes(15 * $attempts) : null,
        ])->save();
    }

    public function shouldDeferOptionalChannel(): bool
    {
        return $this->quietHours->isQuiet();
    }
}
