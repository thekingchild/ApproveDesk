<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\User;
use App\Notifications\Concerns\RendersNotificationCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NotificationDigest extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersNotificationCopy;

    /**
     * @param  array<int, array{title: string, reference_number: string|null, kind: string, created_at: string, submission_id?: int|null}>  $items
     */
    public function __construct(public readonly array $items) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return $notifiable->canReceiveNotifications() ? ['mail'] : [];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $copy = $this->renderedCopy(NotificationKind::Digest, [
            'recipient_name' => $notifiable->name,
            'count' => (string) count($this->items),
        ]);
        $mail = (new MailMessage)
            ->subject($copy->subject)
            ->greeting('Hello '.$notifiable->name.',');
        foreach ($copy->lines as $line) {
            $mail->line($line);
        }

        foreach (array_slice($this->items, 0, 20) as $item) {
            $reference = $item['reference_number'] ? '['.$item['reference_number'].'] ' : '';
            $mail->line('• '.$reference.$item['title']);
        }

        return $mail->action('Open ApproveDesk', route('dashboard'));
    }

    /**
     * Not stored in-app (the digest is mail only); lists the requests it
     * mentions so each one's evidence pack records the email (RV-DATA-09).
     *
     * @return array{kind: string, submission_ids: list<int>}
     */
    public function toArray(User $notifiable): array
    {
        return [
            'kind' => 'digest',
            'submission_ids' => array_values(array_unique(array_filter(array_map(
                fn (array $item): ?int => isset($item['submission_id']) ? (int) $item['submission_id'] : null,
                $this->items,
            )))),
        ];
    }
}
