<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\OptionalNotificationChannel;
use App\DTOs\ChannelTestResult;
use App\DTOs\OutboundNotification;
use App\Enums\NotificationChannelKey;
use App\Models\User;
use App\Services\Notifications\WebPushService;

final class WebPushNotificationChannel implements OptionalNotificationChannel
{
    public function __construct(private readonly WebPushService $push) {}

    public function key(): NotificationChannelKey
    {
        return NotificationChannelKey::WebPush;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return $this->push->publicKey() !== '';
    }

    public function canReceive(User $notifiable): bool
    {
        return $notifiable->pushSubscriptions()->exists();
    }

    public function send(User $notifiable, OutboundNotification $message): void
    {
        if (! $this->canReceive($notifiable)) {
            return;
        }

        $notifiable->loadMissing('pushSubscriptions');
        $this->push->send($notifiable, $message);
    }

    public function testConnection(): ChannelTestResult
    {
        return new ChannelTestResult(true, 'Browser push is ready. People opt in from their Notifications settings.');
    }
}
