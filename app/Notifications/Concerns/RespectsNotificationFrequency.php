<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Services\Notifications\ChannelRegistry;

trait RespectsNotificationFrequency
{
    /** @return array<int, string> */
    private function preferredChannels(User $notifiable): array
    {
        return app(ChannelRegistry::class)->laravelChannels($notifiable);
    }
}
