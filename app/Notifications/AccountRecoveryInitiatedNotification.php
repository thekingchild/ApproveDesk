<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AccountRecoveryInitiatedNotification extends Notification
{
    public function __construct(private readonly string $kind) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $lines = match ($this->kind) {
            'password' => [
                'The system owner invalidated your password and sent a separate reset link.',
                'Use that reset email to choose a new password. Any other signed-in devices have been signed out.',
            ],
            default => [
                'The system owner removed the authenticator, recovery codes, and passkeys on your account.',
                'Sign in with your password and add a new authenticator or passkey from Settings → Security. Other devices have been signed out.',
            ],
        };

        $mail = (new MailMessage)
            ->subject('Your ApproveDesk account recovery')
            ->greeting('Hello '.$notifiable->name);

        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'kind' => 'account_recovery',
            'recovery' => $this->kind,
        ];
    }
}
