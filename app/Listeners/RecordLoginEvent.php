<?php

namespace App\Listeners;

use App\Models\LoginEvent;
use App\Models\User;
use App\Notifications\SuspiciousLoginNotification;
use App\Services\Auth\NewDeviceDetector;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Notification;

/** Records each sign-in and alerts on a new device; NewDeviceDetector decides (RV-ARC-14). */
final class RecordLoginEvent
{
    public function __construct(private readonly NewDeviceDetector $devices) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $request = request();
        $ip = $request?->ip();
        $userAgent = $request?->userAgent();
        $check = $this->devices->check($user, $ip, $userAgent);

        if ($check->duplicate) {
            return;
        }

        LoginEvent::query()->create([
            'user_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'fingerprint' => $check->fingerprint,
            'was_new_device' => $check->newDevice,
            'notified' => $check->newDevice,
            'created_at' => now(),
        ]);

        if ($check->newDevice) {
            Notification::send($user, new SuspiciousLoginNotification(
                ipAddress: (string) $ip,
                userAgent: (string) $userAgent,
                occurredAt: now(),
            ));
        }
    }
}
