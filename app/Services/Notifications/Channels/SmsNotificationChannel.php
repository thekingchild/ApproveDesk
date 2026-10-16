<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\OptionalNotificationChannel;
use App\DTOs\ChannelTestResult;
use App\DTOs\OutboundNotification;
use App\Enums\NotificationChannelKey;
use App\Exceptions\NotificationDeliveryException;
use App\Models\User;

/**
 * Interface member only until SMS delivery ships. Never selected by the registry.
 */
final class SmsNotificationChannel implements OptionalNotificationChannel
{
    public function key(): NotificationChannelKey
    {
        return NotificationChannelKey::Sms;
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

    public function send(User $notifiable, OutboundNotification $message): void
    {
        throw NotificationDeliveryException::skipped('SMS delivery is not available.');
    }

    public function testConnection(): ChannelTestResult
    {
        return new ChannelTestResult(false, 'SMS is not an administrator option yet.');
    }
}
