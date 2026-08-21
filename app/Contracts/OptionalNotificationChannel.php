<?php

namespace App\Contracts;

use App\DTOs\ChannelTestResult;

interface OptionalNotificationChannel extends NotificationChannel
{
    public function isEnabled(): bool;

    public function testConnection(): ChannelTestResult;
}
