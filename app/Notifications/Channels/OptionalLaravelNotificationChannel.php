<?php

namespace App\Notifications\Channels;

use App\Contracts\NotificationChannel;
use App\DTOs\OutboundNotification;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification driver adapter. Fail-open: send errors are recorded
 * and swallowed so optional channels never block mail or the in-app inbox.
 */
final class OptionalLaravelNotificationChannel
{
    public function __construct(private readonly NotificationChannel $driver) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        $this->driver->send($notifiable, OutboundNotification::fromLaravel($notification, $notifiable));
    }
}
