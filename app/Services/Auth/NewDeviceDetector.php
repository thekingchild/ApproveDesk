<?php

namespace App\Services\Auth;

use App\DTOs\LoginDeviceCheck;
use App\Models\LoginEvent;
use App\Models\User;

/**
 * Decides whether a sign-in comes from a device this person has not used
 * before (RV-ARC-14: the rule lived in the RecordLoginEvent listener). A first
 * ever sign-in is not "new": there is nothing to compare it with.
 */
final class NewDeviceDetector
{
    /** Repeat events from the same device inside this window are one sign-in. */
    public const DUPLICATE_WINDOW_SECONDS = 60;

    public function check(User $user, ?string $ip, ?string $userAgent): LoginDeviceCheck
    {
        $fingerprint = LoginEvent::fingerprint($ip, $userAgent);
        $events = LoginEvent::query()->where('user_id', $user->id);

        $duplicate = (clone $events)
            ->where('fingerprint', $fingerprint)
            ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
            ->exists();

        $newDevice = ! $duplicate
            && (clone $events)->exists()
            && ! (clone $events)->where('fingerprint', $fingerprint)->exists();

        return new LoginDeviceCheck($fingerprint, $duplicate, $newDevice);
    }
}
