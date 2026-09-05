<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DisplayNameChangeDecidedNotification extends Notification
{
    public function __construct(
        public readonly string $status,
        public readonly string $proposedName,
        public readonly ?string $reason = null,
    ) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your display-name request was '.$this->status)
            ->greeting('Hello '.$notifiable->name)
            ->line('Your request to use “'.$this->proposedName.'” was '.$this->status.'.');

        if ($this->reason !== null && trim($this->reason) !== '') {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail->action('Open profile settings', route('profile.edit'));
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'kind' => 'display_name_change',
            'status' => $this->status,
            'proposed_name' => $this->proposedName,
            'reason' => $this->reason,
        ];
    }
}
