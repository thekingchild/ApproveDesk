<?php

namespace App\Notifications\Channels;

use App\Contracts\NotificationChannel;
use App\DTOs\OutboundNotification;
use App\Enums\NotificationDeliveryStatus;
use App\Models\User;
use App\Services\Notifications\NotificationDeliveryRecorder;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Laravel notification driver adapter. Fail-open: send errors are recorded
 * and swallowed so optional channels never block mail or the in-app inbox.
 */
final class OptionalLaravelNotificationChannel
{
    public function __construct(
        private readonly NotificationChannel $driver,
        private readonly NotificationDeliveryRecorder $recorder,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        $message = OutboundNotification::fromLaravel($notification, $notifiable);
        $delivery = $this->recorder->start(
            $notifiable,
            $message->kind,
            $this->driver->key()->value,
            $message->payload,
        );

        if ($delivery->status === NotificationDeliveryStatus::Deferred) {
            return;
        }

        try {
            $this->driver->send($notifiable, $message);
            $this->recorder->markSent($delivery);
        } catch (Throwable $exception) {
            $this->recorder->markFailed($delivery, $exception->getMessage(), true);
        }
    }
}
