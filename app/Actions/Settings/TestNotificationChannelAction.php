<?php

namespace App\Actions\Settings;

use App\Contracts\OptionalNotificationChannel;
use App\DTOs\ChannelTestResult;
use App\Enums\NotificationChannelKey;
use App\Services\Notifications\ChannelRegistry;
use App\Services\Notifications\ChannelSettings;
use InvalidArgumentException;

final class TestNotificationChannelAction
{
    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly ChannelSettings $settings,
    ) {}

    public function execute(NotificationChannelKey $channel): ChannelTestResult
    {
        $driver = $this->registry->driver($channel);
        if (! $driver instanceof OptionalNotificationChannel || ! $channel->isAdministratorOption()) {
            throw new InvalidArgumentException('This channel cannot be tested.');
        }

        $result = $driver->testConnection();
        $this->settings->recordTest($channel, $result->ok, $result->message);

        return $result;
    }
}
