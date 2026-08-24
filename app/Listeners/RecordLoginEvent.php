<?php

namespace App\Listeners;

use App\Models\LoginEvent;
use App\Models\User;
use App\Notifications\SuspiciousLoginNotification;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Notification;

final class RecordLoginEvent
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $request = request();
        $ip = $request?->ip();
        $userAgent = $request?->userAgent();
        $fingerprint = LoginEvent::fingerprint($ip, $userAgent);

        $hadPrior = LoginEvent::query()->where('user_id', $user->id)->exists();
        $known = LoginEvent::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->exists();

        $duplicate = LoginEvent::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($duplicate) {
            return;
        }

        $isNew = $hadPrior && ! $known;

        LoginEvent::query()->create([
            'user_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'fingerprint' => $fingerprint,
            'was_new_device' => $isNew,
            'notified' => $isNew,
            'created_at' => now(),
        ]);

        if ($isNew) {
            Notification::send($user, new SuspiciousLoginNotification(
                ipAddress: (string) $ip,
                userAgent: (string) $userAgent,
                occurredAt: now(),
            ));
        }
    }
}
