<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NotificationDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{title: string, reference_number: string|null, kind: string, created_at: string}>  $items
     */
    public function __construct(public readonly array $items) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your ApproveDesk notification digest')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('You have '.count($this->items).' approval update(s) since your last digest.');

        foreach (array_slice($this->items, 0, 20) as $item) {
            $reference = $item['reference_number'] ? '['.$item['reference_number'].'] ' : '';
            $mail->line('• '.$reference.$item['title']);
        }

        return $mail->action('Open ApproveDesk', route('dashboard'));
    }
}
