<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\Comment;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\Concerns\RendersNotificationCopy;
use App\Notifications\Concerns\RespectsNotificationFrequency;
use App\Services\Notifications\NotificationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MentionedInCommentNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersNotificationCopy;
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
        return $this->mailFromCopy(
            $notifiable,
            NotificationKind::Mention,
            NotificationContext::forSubmission($this->submission, $notifiable, [
                'comment' => $this->comment->author?->name.' mentioned you.',
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
            'kind' => 'mention',
            'comment_id' => $this->comment->id,
            'delivery_frequency' => $notifiable->notification_frequency->value,
        ];
    }
}
