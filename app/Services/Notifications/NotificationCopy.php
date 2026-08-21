<?php

namespace App\Services\Notifications;

use App\DTOs\RenderedNotificationCopy;
use App\Enums\NotificationKind;
use App\Models\NotificationEmailTemplate;

final class NotificationCopy
{
    /**
     * @param  array<string, string|null>  $context
     */
    public function render(NotificationKind|string $kind, array $context): RenderedNotificationCopy
    {
        $kind = $kind instanceof NotificationKind ? $kind : NotificationKind::from($kind);
        $stored = NotificationEmailTemplate::query()->where('kind', $kind->value)->first();
        $defaults = $this->defaults($kind);

        $subject = $stored?->subject ?: $defaults['subject'];
        $body = $stored?->body ?: $defaults['body'];

        $subject = $this->merge($subject, $context);
        $body = $this->merge($body, $context);

        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), preg_split('/\r\n|\r|\n/', $body) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        $actionUrl = $kind === NotificationKind::ActionRequired
            ? ($context['approval_url'] ?? $context['submission_url'] ?? null)
            : ($context['submission_url'] ?? null);

        $actionLabel = $kind === NotificationKind::ActionRequired ? 'Review and approve securely' : 'Open request';

        return new RenderedNotificationCopy($subject, $body, $lines, $actionUrl, $actionLabel);
    }

    /**
     * @param  array<string, string|null>  $context
     */
    public function merge(string $template, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $matches) use ($context): string {
            $key = $matches[1];

            return array_key_exists($key, $context) && $context[$key] !== null
                ? (string) $context[$key]
                : $matches[0];
        }, $template);
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function defaults(NotificationKind $kind): array
    {
        return match ($kind) {
            NotificationKind::ActionRequired => [
                'subject' => '[{{reference_number}}] Action required: {{title}}',
                'body' => "A new submission is waiting for your approval ({{reference_number}}).\nTitle: {{title}}\nSubmitted by: {{submitter_name}}\nPriority: {{priority}}\nThis secure link expires after the organisation's approval-link lifetime. Opening it does not approve automatically: {{approval_url}}",
            ],
            NotificationKind::Decided => [
                'subject' => '[{{reference_number}}] {{decision}}: {{title}}',
                'body' => "Your submission status is now {{decision}}.\nTitle: {{title}}\n{{comment}}",
            ],
            NotificationKind::Nudge => [
                'subject' => '[{{reference_number}}] Reminder: a decision is waiting',
                'body' => "Someone sent a reminder about {{title}}.\n{{comment}}",
            ],
            NotificationKind::Mention => [
                'subject' => '[{{reference_number}}] You were mentioned',
                'body' => 'You were mentioned on {{title}}.',
            ],
            NotificationKind::Comment => [
                'subject' => '[{{reference_number}}] New comment',
                'body' => 'A new comment was added on {{title}}.',
            ],
            NotificationKind::WatcherStatus => [
                'subject' => '[{{reference_number}}] Status update',
                'body' => '{{title}} is now {{decision}}.',
            ],
            NotificationKind::Delegated => [
                'subject' => '[{{reference_number}}] Approval delegated to you',
                'body' => 'An approval was delegated to you for {{title}}.',
            ],
            NotificationKind::Digest => [
                'subject' => 'Your ApproveDesk notification digest',
                'body' => 'You have {{count}} approval update(s) since your last digest.',
            ],
        };
    }

    public function requiresApprovalUrl(NotificationKind $kind, string $body): bool
    {
        return $kind === NotificationKind::ActionRequired && ! str_contains($body, '{{approval_url}}');
    }
}
