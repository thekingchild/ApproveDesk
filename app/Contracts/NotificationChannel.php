<?php

namespace App\Contracts;

use App\DTOs\OutboundNotification;
use App\Enums\NotificationChannelKey;
use App\Models\User;

interface NotificationChannel
{
    public function key(): NotificationChannelKey;

    public function isConfigured(): bool;

    public function canReceive(User $notifiable): bool;

    public function send(User $notifiable, OutboundNotification $message): void;
}
