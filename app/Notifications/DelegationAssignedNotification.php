<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\Delegation;
use App\Models\User;
use App\Notifications\Concerns\RendersNotificationCopy;
use App\Notifications\Concerns\RespectsNotificationFrequency;
use App\Services\Notifications\NotificationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DelegationAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersNotificationCopy;
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

        return $this->mailFromCopy(
            $notifiable,
            NotificationKind::Delegated,
            NotificationContext::forSubmission($submission, $notifiable, [
                'comment' => 'Expires '.$this->delegation->expires_at->format('d M Y H:i'),
            ]),
        );
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
