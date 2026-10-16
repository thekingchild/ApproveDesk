<?php

namespace App\Jobs;

use App\DTOs\OutboundNotification;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Collaboration\QuietHours;
use App\Services\Notifications\ChannelRegistry;
use App\Services\Notifications\NotificationDeliveryRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RetryNotificationDeliveries implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One copy queued or running at a time; the schedule's withoutOverlapping()
     * only guards dispatch, so copies piled up when the worker lagged (RV-OPS-02).
     * The lock lapses after this many seconds if a worker is killed mid-job.
     */
    public int $uniqueFor = 900;

    public function handle(
        QuietHours $quietHours,
        ChannelRegistry $registry,
        NotificationDeliveryRecorder $recorder,
    ): void {
        if ($quietHours->isQuiet()) {
            return;
        }

        $deliveries = NotificationDelivery::query()
            ->whereIn('status', [NotificationDeliveryStatus::Failed->value, NotificationDeliveryStatus::Deferred->value])
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->where('attempts', '<', 3)
            ->limit(50)
            ->get();

        foreach ($deliveries as $delivery) {
            $user = User::query()->find($delivery->user_id);
            if ($user === null) {
                continue;
            }

            try {
                $driver = $registry->driver($delivery->channel);
                if (! $driver->isConfigured() || ! $driver->canReceive($user)) {
                    $recorder->markSkipped($delivery, 'Recipient can no longer receive this channel.');

                    continue;
                }

                $payload = $delivery->payload ?? [];
                $message = new OutboundNotification(
                    kind: $delivery->kind,
                    subject: (string) ($payload['title'] ?? $delivery->kind),
                    lines: [],
                    actionUrl: isset($payload['submission_id']) ? route('submissions.show', $payload['submission_id']) : null,
                    actionLabel: 'Open request',
                    payload: $payload,
                );
                $driver->send($user, $message);
                $recorder->markSent($delivery);
            } catch (Throwable $exception) {
                $recorder->markFailed($delivery, $exception->getMessage(), true);
            }
        }
    }
}
