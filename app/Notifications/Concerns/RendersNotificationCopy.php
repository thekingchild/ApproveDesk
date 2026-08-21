<?php

namespace App\Notifications\Concerns;

use App\DTOs\RenderedNotificationCopy;
use App\Enums\NotificationKind;
use App\Models\User;
use App\Services\Notifications\NotificationCopy;
use Illuminate\Notifications\Messages\MailMessage;

trait RendersNotificationCopy
{
    /**
     * @param  array<string, string|null>  $context
     */
    protected function renderedCopy(NotificationKind $kind, array $context): RenderedNotificationCopy
    {
        return app(NotificationCopy::class)->render($kind, $context);
    }

    /**
     * @param  array<string, string|null>  $context
     */
    protected function mailFromCopy(User $notifiable, NotificationKind $kind, array $context): MailMessage
    {
        $copy = $this->renderedCopy($kind, $context);
        $mail = (new MailMessage)
            ->subject($copy->subject)
            ->greeting('Hello '.$notifiable->name.',');

        foreach ($copy->lines as $line) {
            $mail->line($line);
        }

        if ($copy->actionUrl) {
            $mail->action($copy->actionLabel ?? 'Open request', $copy->actionUrl);
        }

        return $mail;
    }
}
