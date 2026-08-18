<?php

namespace App\Notifications;

use App\Models\Delegation;
use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationFrequency;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DelegationAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationFrequency;

    public function __construct(public readonly Delegation $delegation) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return $this->preferredChannels($notifiable);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $submission = $this->delegation->instance->submission;

        return (new MailMessage)
            ->subject("[{$submission->reference_number}] Approval delegated to you")
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->delegation->delegator->name.' delegated an approval to you.')
            ->line('Expires: '.$this->delegation->expires_at->format('d M Y H:i'))
            ->action('Review submission', route('submissions.show', $submission));
    }

    /** @return array{kind: string, submission_id: int, reference_number: string|null, title: string, delegator_id: int, expires_at: string, delivery_frequency: string} */
    public function toArray(User $notifiable): array
    {
        $submission = $this->delegation->instance->submission;

        return [
            'kind' => 'delegated',
            'submission_id' => $submission->id,
            'reference_number' => $submission->reference_number,
            'title' => $submission->title,
            'delegator_id' => $this->delegation->delegator_id,
            'expires_at' => $this->delegation->expires_at->toIso8601String(),
            'delivery_frequency' => $notifiable->notification_frequency->value,
        ];
    }
}
