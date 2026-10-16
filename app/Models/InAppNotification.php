<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Laravel's `notifications` table (the in-app inbox), given a prune rule so it
 * does not grow forever (RV-OPS-05). Unread notifications are never pruned.
 */
class InAppNotification extends DatabaseNotification
{
    use MassPrunable;

    /** Read notifications older than this are cleared from the inbox. */
    public const KEEP_READ_DAYS = 180;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(self::KEEP_READ_DAYS));
    }
}
