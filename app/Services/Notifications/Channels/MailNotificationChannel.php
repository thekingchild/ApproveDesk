<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\NotificationChannel;
use App\DTOs\OutboundNotification;
use App\Enums\NotificationChannelKey;
use App\Enums\NotificationFrequency;
use App\Models\User;

/**
 * Generic email. Laravel's mail channel delivers it. Immediate users only;
 * digest and in-app-only frequencies keep mail off this path.
 */
final class MailNotificationChannel implements NotificationChannel
{
    public function key(): NotificationChannelKey
    {
        return NotificationChannelKey::Mail;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function canReceive(User $notifiable): bool
    {
        return $notifiable->notification_frequency === NotificationFrequency::Immediate;
    }

    public function send(User $notifiable, OutboundNotification $message): void
    {
        // Delivered by Laravel's mail channel from via().
    }
}
