<?php

namespace App\Actions\Settings;

use App\Enums\NotificationChannelKey;
use App\Services\Notifications\ChannelSettings;
use InvalidArgumentException;

final class StoreNotificationChannelCredentialsAction
{
    public function __construct(private readonly ChannelSettings $settings) {}

    /**
     * @param  array<string, string|null>  $credentials
     */
    public function execute(NotificationChannelKey $channel, array $credentials, bool $clear = false): void
    {
        if (! $channel->isAdministratorOption()) {
            throw new InvalidArgumentException('This channel does not store administrator credentials.');
        }

        if ($clear) {
            $this->settings->clearCredentials($channel);

            return;
        }

        $this->settings->putCredentials($channel, $credentials);
    }
}
