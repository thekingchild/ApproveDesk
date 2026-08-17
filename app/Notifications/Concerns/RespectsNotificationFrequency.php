<?php

namespace App\Notifications\Concerns;

use App\Enums\NotificationFrequency;
use App\Models\User;

trait RespectsNotificationFrequency
{
    /** @return array<int, string> */
    private function preferredChannels(User $notifiable): array
    {
        return $notifiable->notification_frequency === NotificationFrequency::Immediate
            ? ['mail', 'database']
            : ['database'];
    }
}
