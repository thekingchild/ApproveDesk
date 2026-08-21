<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\SubmissionNudge;
use App\Models\User;
use App\Notifications\Concerns\RendersNotificationCopy;
use App\Notifications\Concerns\RespectsNotificationFrequency;
use App\Services\Notifications\NotificationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubmissionNudgeNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersNotificationCopy;
    use RespectsNotificationFrequency;

    public function __construct(public readonly SubmissionNudge $nudge) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return $this->preferredChannels($notifiable);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $submission = $this->nudge->submission;

        return $this->mailFromCopy(
            $notifiable,
            NotificationKind::Nudge,
            NotificationContext::forSubmission($submission, $notifiable, [
                'comment' => $this->nudge->message,
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        $submission = $this->nudge->submission;

        return [
            'submission_id' => $submission->id,
            'reference_number' => $submission->reference_number,
            'title' => $submission->title,
            'kind' => 'nudge',
            'nudge_id' => $this->nudge->id,
            'delivery_frequency' => $notifiable->notification_frequency->value,
        ];
    }
}
