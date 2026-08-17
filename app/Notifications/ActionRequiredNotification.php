<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to every assignee of the step that just became active.
 */
class ActionRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Submission $submission) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->submission->reference_number}] Action required: {$this->submission->title}")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("A new submission is waiting for your approval ({$this->submission->reference_number}).")
            ->line('Title: '.$this->submission->title)
            ->line('Submitted by: '.$this->submission->submitter->name)
            ->line('Priority: '.$this->submission->priority->value)
            ->action('Review submission', route('submissions.show', $this->submission))
            ->line('Thank you for keeping approvals moving.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'submission_id' => $this->submission->id,
            'reference_number' => $this->submission->reference_number,
            'title' => $this->submission->title,
            'kind' => 'action_required',
        ];
    }
}
