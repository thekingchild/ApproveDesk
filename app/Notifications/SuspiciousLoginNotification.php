<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Queued so a mail outage can't fail the sign-in or admin action that triggers it (RV-LAR-06). */
final class SuspiciousLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly CarbonInterface $occurredAt,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New sign-in to your ApproveDesk account')
            ->greeting('Hello '.$notifiable->name)
            ->line('Someone signed in to your account from a device or location we have not seen before.')
            ->line('Time: '.$this->occurredAt->toDayDateTimeString())
            ->line('IP address: '.($this->ipAddress !== '' ? $this->ipAddress : 'unknown'))
            ->line('Browser: '.($this->userAgent !== '' ? $this->userAgent : 'unknown'))
            ->line('If this was you, you can ignore this message. If it was not, change your password and sign out other devices from Settings → Security.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'kind' => 'suspicious_login',
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'occurred_at' => $this->occurredAt->toIso8601String(),
        ];
    }
}
