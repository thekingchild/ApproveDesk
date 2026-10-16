<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannel;
use App\DTOs\OutboundNotification;
use App\Enums\NotificationDeliveryStatus;
use App\Exceptions\NotificationDeliveryException;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Collaboration\QuietHours;
use Throwable;

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
            'submission_id' => is_numeric($payload['submission_id'] ?? null) ? (int) $payload['submission_id'] : null,
            'kind' => $kind,
            'channel' => $channel,
            'status' => $deferred ? NotificationDeliveryStatus::Deferred : NotificationDeliveryStatus::Queued,
            'attempts' => 0,
            'payload' => $payload,
            'next_retry_at' => $deferred ? $this->quietHours->nextOpenAt() : null,
        ]);
    }

    /**
     * Sends through the driver and records what really happened: Sent only
     * when the provider accepted the message, Skipped when the channel could
     * not carry it, Failed (with a retry for critical kinds) otherwise
     * (RV-BUG-15). Never throws: optional channels stay fail-open.
     */
    public function deliver(NotificationChannel $driver, User $user, OutboundNotification $message, NotificationDelivery $delivery): void
    {
        try {
            $driver->send($user, $message);
        } catch (NotificationDeliveryException $exception) {
            $exception->skipped
                ? $this->markSkipped($delivery, $exception->getMessage())
                : $this->markFailed($delivery, $exception->getMessage(), true);

            return;
        } catch (Throwable $exception) {
            $this->markFailed($delivery, $exception->getMessage(), true);

            return;
        }

        $this->markSent($delivery);
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
            'status' => NotificationDeliveryStatus::Failed,
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
