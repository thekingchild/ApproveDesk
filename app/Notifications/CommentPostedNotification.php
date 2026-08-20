<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationFrequency;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommentPostedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationFrequency;

    public function __construct(
        public readonly Submission $submission,
        public readonly Comment $comment,
    ) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return $this->preferredChannels($notifiable);
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->submission->reference_number}] New comment")
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->comment->author?->name.' commented on '.$this->submission->title.'.')
            ->action('Open request', route('submissions.show', $this->submission));
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'submission_id' => $this->submission->id,
            'reference_number' => $this->submission->reference_number,
            'title' => $this->submission->title,
            'kind' => 'comment',
            'comment_id' => $this->comment->id,
            'delivery_frequency' => $notifiable->notification_frequency->value,
        ];
    }
}
