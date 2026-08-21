<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\Concerns\RendersNotificationCopy;
use App\Notifications\Concerns\RespectsNotificationFrequency;
use App\Services\Notifications\NotificationContext;
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
    use RendersNotificationCopy;
    use RespectsNotificationFrequency;

    public function __construct(public readonly Submission $submission) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return $this->preferredChannels($notifiable);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $this->submission->loadMissing('submitter');

        return $this->mailFromCopy(
            $notifiable,
            NotificationKind::ActionRequired,
            NotificationContext::forSubmission($this->submission, $notifiable),
        );
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
            'delivery_frequency' => $notifiable->notification_frequency->value,
        ];
    }
}
