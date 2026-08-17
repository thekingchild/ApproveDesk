<?php

namespace App\Notifications;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationFrequency;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the submitter the outcome of their submission:
 * approved, rejected, or returned for revision.
 */
class SubmissionDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationFrequency;

    public function __construct(
        public readonly Submission $submission,
        public readonly SubmissionStatus $decision,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return $this->preferredChannels($notifiable);
    }

    public function toMail(User $notifiable): MailMessage
    {
        [$subject, $headline, $reason] = match ($this->decision) {
            SubmissionStatus::Approved => [
                "[{$this->submission->reference_number}] Approved: {$this->submission->title}",
                'Good news — your submission has been approved.',
                null,
            ],
            SubmissionStatus::Rejected => [
                "[{$this->submission->reference_number}] Rejected: {$this->submission->title}",
                'Your submission was rejected.',
                $this->lastDecisionComment(),
            ],
            SubmissionStatus::Returned => [
                "[{$this->submission->reference_number}] Returned for revision: {$this->submission->title}",
                'Your submission was returned for revision.',
                $this->lastDecisionComment(),
            ],
            SubmissionStatus::AwaitingInformation => [
                "[{$this->submission->reference_number}] Information requested: {$this->submission->title}",
                'An approver requested more information for your submission.',
                $this->lastDecisionComment(),
            ],
            default => [
                "[{$this->submission->reference_number}] Update: {$this->submission->title}",
                'Your submission status changed to '.$this->decision->value.'.',
                null,
            ],
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($headline)
            ->line('Title: '.$this->submission->title);

        if ($reason !== null) {
            $mail->line('Reason: '.$reason);
        }

        return $mail->action('View submission', route('submissions.show', $this->submission));
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
            'kind' => 'decided',
            'decision' => $this->decision->value,
        ];
    }

    private function lastDecisionComment(): ?string
    {
        $instance = $this->submission->workflowInstance()->first();

        return $instance?->actions()
            ->whereIn('action', ['reject', 'return', 'request_information'])
            ->latest('id')
            ->first()
            ?->comment;
    }
}
