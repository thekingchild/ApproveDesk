<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\Concerns\RendersNotificationCopy;
use App\Notifications\Concerns\RespectsNotificationFrequency;
use App\Services\Notifications\NotificationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WatcherStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersNotificationCopy;
    use RespectsNotificationFrequency;

    public function __construct(
        public readonly Submission $submission,
        public readonly SubmissionStatus $decision,
    ) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return $this->preferredChannels($notifiable);
    }

    public function toMail(User $notifiable): MailMessage
    {
        return $this->mailFromCopy(
            $notifiable,
            NotificationKind::WatcherStatus,
            NotificationContext::forSubmission($this->submission, $notifiable, [
                'decision' => $this->decision->value,
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'submission_id' => $this->submission->id,
            'reference_number' => $this->submission->reference_number,
            'title' => $this->submission->title,
            'kind' => 'watcher_status',
            'decision' => $this->decision->value,
            'delivery_frequency' => $notifiable->notification_frequency->value,
        ];
    }
}
