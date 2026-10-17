<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Notifications\NotificationDeliveryRecorder;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records each email about a request in notification_deliveries, as the chat
 * channels already do, so the request's evidence pack lists who was emailed
 * and when (RV-DATA-09). A digest records one row per request it mentions.
 * Emails that are not about a request (sign-in alerts, account recovery) are
 * not recorded.
 */
final class RecordEmailDelivery
{
    public function __construct(private readonly NotificationDeliveryRecorder $recorder) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'mail' || ! $event->notifiable instanceof User || ! method_exists($event->notification, 'toArray')) {
            return;
        }

        // The email has already gone; failing here would make the queue send it again.
        try {
            $data = $event->notification->toArray($event->notifiable);
            $kind = (string) ($data['kind'] ?? Str::snake(class_basename($event->notification)));
            $ids = $data['submission_ids'] ?? [$data['submission_id'] ?? null];

            foreach (array_unique(array_filter((array) $ids, is_numeric(...))) as $submissionId) {
                $this->recorder->recordSent($event->notifiable, (int) $submissionId, $kind, 'mail', [
                    'notification' => class_basename($event->notification),
                    'reference_number' => $data['reference_number'] ?? null,
                ]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
