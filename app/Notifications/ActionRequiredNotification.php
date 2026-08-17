<?php

namespace App\Notifications;

use App\Models\Submission;
use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationFrequency;
use App\Support\EmailApprovalLinks;
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
        $instance = $this->submission->workflowInstance()->with('currentStep')->firstOrFail();
        $step = $instance->currentStep;

        return (new MailMessage)
            ->subject("[{$this->submission->reference_number}] Action required: {$this->submission->title}")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("A new submission is waiting for your approval ({$this->submission->reference_number}).")
            ->line('Title: '.$this->submission->title)
            ->line('Submitted by: '.$this->submission->submitter->name)
            ->line('Priority: '.$this->submission->priority->value)
            ->action('Review and approve securely', EmailApprovalLinks::confirmationUrl($instance, $step, $notifiable))
            ->line('This secure link expires in '.EmailApprovalLinks::expirationHours().' hours. Opening it does not approve automatically; you must confirm the action.')
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
