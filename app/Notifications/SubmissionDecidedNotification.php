<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
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
 * Tells the submitter the outcome of their submission:
 * approved, rejected, or returned for revision.
 */
class SubmissionDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersNotificationCopy;
    use RespectsNotificationFrequency;

    /** @return list<WorkflowActionType> the actions that put a request in $status */
    private static function decidingActions(SubmissionStatus $status): array
    {
        return match ($status) {
            SubmissionStatus::Approved => [WorkflowActionType::Approve, WorkflowActionType::ApproveWithConditions, WorkflowActionType::AutoApprove],
            SubmissionStatus::Rejected => [WorkflowActionType::Reject, WorkflowActionType::AutoReject],
            SubmissionStatus::Returned => [WorkflowActionType::Return],
            SubmissionStatus::AwaitingInformation => [WorkflowActionType::RequestInformation],
            default => [],
        };
    }

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
        return $this->mailFromCopy(
            $notifiable,
            NotificationKind::Decided,
            NotificationContext::forSubmission($this->submission, $notifiable, [
                'decision' => $this->decision->value,
                'comment' => $this->lastDecisionComment(),
            ]),
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
            'kind' => 'decided',
            'decision' => $this->decision->value,
            'delivery_frequency' => $notifiable->notification_frequency->value,
        ];
    }

    /**
     * The comment on the action that produced this decision. An older return
     * or rejection comment must not appear on a later approval (RV-BUG-17).
     */
    private function lastDecisionComment(): ?string
    {
        $instance = $this->submission->workflowInstance()->first();

        // actions() is ordered oldest first; reorder so latest() really is the newest.
        $latest = $instance?->actions()
            ->whereIn('action', array_merge(...array_map(self::decidingActions(...), [SubmissionStatus::Approved, SubmissionStatus::Rejected, SubmissionStatus::Returned, SubmissionStatus::AwaitingInformation])))
            ->reorder()
            ->latest('id')
            ->first();

        if ($latest === null || ! in_array($latest->action, self::decidingActions($this->decision), true)) {
            return null;
        }

        $comment = trim((string) $latest->comment);

        return $comment !== '' ? $comment : null;
    }
}
