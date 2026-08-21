<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\NotificationChannel;
use App\DTOs\OutboundNotification;
use App\Enums\NotificationChannelKey;
use App\Models\User;

/**
 * Generic in-app inbox. Laravel's database notification channel delivers it.
 */
final class DatabaseNotificationChannel implements NotificationChannel
{
    public function key(): NotificationChannelKey
    {
        return NotificationChannelKey::Database;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function canReceive(User $notifiable): bool
    {
        return true;
    }

    public function send(User $notifiable, OutboundNotification $message): void
    {
        // Delivered by Laravel's database channel from via().
    }
}
