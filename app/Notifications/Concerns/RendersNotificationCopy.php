<?php

namespace App\Notifications\Concerns;

use App\DTOs\RenderedNotificationCopy;
use App\Enums\NotificationKind;
use App\Models\User;
use App\Services\Notifications\NotificationCopy;
use App\Support\MarkdownText;
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
        // Mail lines are Markdown: user-supplied values (titles, comments, names)
        // must stay literal. URLs are left intact for the action button.
        $safe = [];
        foreach ($context as $key => $value) {
            $safe[$key] = $value === null || str_ends_with((string) $key, '_url') ? $value : MarkdownText::escape($value);
        }

        $copy = $this->renderedCopy($kind, $safe);
        $mail = (new MailMessage)
            ->subject($copy->subject)
            ->greeting('Hello '.MarkdownText::escape($notifiable->name).',');

        foreach ($copy->lines as $line) {
            $mail->line($line);
        }

        if ($copy->actionUrl) {
            $mail->action($copy->actionLabel ?? 'Open request', $copy->actionUrl);
        }

        return $mail;
    }
}
