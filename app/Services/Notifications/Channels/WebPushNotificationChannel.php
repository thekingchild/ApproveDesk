<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\OptionalNotificationChannel;
use App\DTOs\ChannelTestResult;
use App\DTOs\OutboundNotification;
use App\Enums\NotificationChannelKey;
use App\Models\User;

/**
 * Interface member only until P8-NOT-03. Never selected by the registry.
 */
final class WebPushNotificationChannel implements OptionalNotificationChannel
{
    public function key(): NotificationChannelKey
    {
        return NotificationChannelKey::WebPush;
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function canReceive(User $notifiable): bool
    {
        return false;
    }

    public function send(User $notifiable, OutboundNotification $message): void {}

    public function testConnection(): ChannelTestResult
    {
        return new ChannelTestResult(false, 'Web push ships with the installable app shell.');
    }
}
