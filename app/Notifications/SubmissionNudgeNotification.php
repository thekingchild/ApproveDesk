<?php

namespace App\Notifications;

use App\Models\SubmissionNudge;
use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationFrequency;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubmissionNudgeNotification extends Notification implements ShouldQueue
{
    use Queueable;
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
        $mail = (new MailMessage)
            ->subject("[{$submission->reference_number}] Reminder: a decision is waiting")
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->nudge->actor?->name.' sent a reminder about '.$submission->title.'.');

        if ($this->nudge->message) {
            $mail->line('Note: '.$this->nudge->message);
        }

        return $mail->action('Open request', route('submissions.show', $submission));
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
