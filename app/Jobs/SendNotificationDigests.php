<?php

namespace App\Jobs;

use App\Enums\NotificationFrequency;
use App\Models\User;
use App\Notifications\NotificationDigest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationDigests implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        User::query()
            ->whereIn('notification_frequency', [
                NotificationFrequency::Daily,
                NotificationFrequency::Weekly,
            ])
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $this->sendWhenDue($user);
                }
            });
    }

    private function sendWhenDue(User $user): void
    {
        $days = $user->notification_frequency === NotificationFrequency::Weekly ? 7 : 1;
        $lastSent = $user->last_notification_digest_at;

        if ($lastSent !== null && $lastSent->isAfter(now()->subDays($days))) {
            return;
        }

        $since = $lastSent ?? now()->subDays($days);
        $notifications = $user->notifications()
            ->where('created_at', '>', $since)
            ->oldest('created_at')
            ->get();

        if ($notifications->isEmpty()) {
            return;
        }

        $items = $notifications->map(fn ($notification) => [
            'title' => (string) ($notification->data['title'] ?? 'Approval update'),
            'reference_number' => isset($notification->data['reference_number'])
                ? (string) $notification->data['reference_number']
                : null,
            'kind' => (string) ($notification->data['kind'] ?? 'update'),
            'created_at' => $notification->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ])->all();

        $user->notify(new NotificationDigest($items));
        $user->update(['last_notification_digest_at' => now()]);
    }
}
