<?php

namespace App\Services\Notifications;

use App\Enums\NotificationFrequency;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * When a person's notification digest is due and what it covers (RV-ARC-14:
 * the rule lived in the SendNotificationDigests job).
 */
final class DigestSchedule
{
    /** Days between digests, or null when the person gets notifications immediately. */
    public function periodDays(NotificationFrequency $frequency): ?int
    {
        return match ($frequency) {
            NotificationFrequency::Daily => 1,
            NotificationFrequency::Weekly => 7,
            NotificationFrequency::Immediate, NotificationFrequency::InAppOnly => null,
        };
    }

    /**
     * Calendar days, not exact instants: a run that starts a few seconds
     * earlier than yesterday's is still due (RV-BUG-18).
     */
    public function isDue(User $user, CarbonInterface $now): bool
    {
        $days = $this->periodDays($user->notification_frequency);
        if ($days === null) {
            return false;
        }

        $lastSent = $user->last_notification_digest_at;

        return $lastSent === null
            || ! $lastSent->copy()->startOfDay()->isAfter($now->copy()->subDays($days)->startOfDay());
    }

    /** Notifications after this moment go into the next digest. */
    public function coversSince(User $user, CarbonInterface $now): CarbonInterface
    {
        return $user->last_notification_digest_at ?? $now->copy()->subDays($this->periodDays($user->notification_frequency) ?? 1);
    }
}
