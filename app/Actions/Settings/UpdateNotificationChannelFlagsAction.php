<?php

namespace App\Actions\Settings;

use App\Enums\NotificationChannelKey;
use App\Services\Notifications\ChannelSettings;
use InvalidArgumentException;

final class UpdateNotificationChannelFlagsAction
{
    public function __construct(private readonly ChannelSettings $settings) {}

    /**
     * @param  array<string, bool>  $enabled
     */
    public function execute(array $enabled): void
    {
        foreach ($enabled as $key => $value) {
            $channel = NotificationChannelKey::tryFrom($key);
            if ($channel === null || ! $channel->isAdministratorOption()) {
                throw new InvalidArgumentException('Unknown optional notification channel.');
            }

            $this->settings->setEnabled($channel, $value);
        }
    }
}
