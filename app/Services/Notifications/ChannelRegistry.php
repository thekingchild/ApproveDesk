<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannel;
use App\Contracts\OptionalNotificationChannel;
use App\Enums\NotificationChannelKey;
use App\Enums\NotificationFrequency;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class ChannelRegistry
{
    /** @var Collection<int, NotificationChannel> */
    private Collection $channels;

    /**
     * @param  iterable<NotificationChannel>  $channels
     */
    public function __construct(iterable $channels)
    {
        $this->channels = Collection::make($channels)->values();
    }

    /**
     * @return Collection<int, NotificationChannel>
     */
    public function all(): Collection
    {
        return $this->channels;
    }

    public function driver(NotificationChannelKey|string $key): NotificationChannel
    {
        $value = $key instanceof NotificationChannelKey ? $key : NotificationChannelKey::from($key);

        $driver = $this->channels->first(
            fn (NotificationChannel $channel): bool => $channel->key() === $value,
        );

        if ($driver === null) {
            throw new InvalidArgumentException('Unknown notification channel ['.$value->value.'].');
        }

        return $driver;
    }

    /**
     * Laravel `via()` list. Mail and database stay the reliability baseline.
     * Optional channels are appended only when enabled, configured, and the
     * user can receive them. Unconfigured drivers are omitted (skip, not fail).
     *
     * @return list<string>
     */
    public function laravelChannels(User $notifiable): array
    {
        $channels = $notifiable->notification_frequency === NotificationFrequency::Immediate
            ? ['mail', 'database']
            : ['database'];

        foreach ($this->channels as $driver) {
            if (! $driver instanceof OptionalNotificationChannel) {
                continue;
            }

            if ($driver->key()->isDeferred()) {
                continue;
            }

            if ($driver->isEnabled() && $driver->isConfigured() && $driver->canReceive($notifiable)) {
                $channels[] = $driver->key()->value;
            }
        }

        return $channels;
    }
}
