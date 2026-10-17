<?php

namespace App\Jobs;

use App\Enums\NotificationFrequency;
use App\Models\User;
use App\Notifications\NotificationDigest;
use App\Services\Notifications\DigestSchedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationDigests implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One copy queued or running at a time; the schedule's withoutOverlapping()
     * only guards dispatch, so copies piled up when the worker lagged (RV-OPS-02).
     * The lock lapses after this many seconds if a worker is killed mid-job.
     */
    public int $uniqueFor = 3600;

    public function handle(DigestSchedule $schedule): void
    {
        User::query()
            // Deactivated and anonymized people get no digest (RV-BUG-20).
            ->where('is_active', true)
            ->whereIn('notification_frequency', [
                NotificationFrequency::Daily,
                NotificationFrequency::Weekly,
            ])
            ->chunkById(100, function ($users) use ($schedule): void {
                foreach ($users as $user) {
                    $this->sendWhenDue($user, $schedule);
                }
            });
    }

    private function sendWhenDue(User $user, DigestSchedule $schedule): void
    {
        $now = now();
        if (! $schedule->isDue($user, $now)) {
            return;
        }

        $notifications = $user->notifications()
            ->where('created_at', '>', $schedule->coversSince($user, $now))
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
        $user->update(['last_notification_digest_at' => $now]);
    }
}
