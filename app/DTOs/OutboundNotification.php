<?php

namespace App\DTOs;

use App\Enums\NotificationKind;
use App\Models\Submission;
use App\Models\User;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationCopy;
use Illuminate\Notifications\Notification;

final readonly class OutboundNotification
{
    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $kind,
        public string $subject,
        public array $lines,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
        public array $payload = [],
    ) {}

    public static function fromLaravel(Notification $notification, User $notifiable): self
    {
        /** @var array<string, mixed> $payload */
        $payload = method_exists($notification, 'toArray')
            ? $notification->toArray($notifiable)
            : [];

        return self::fromPayload($payload, $notifiable, class_basename($notification));
    }

    /**
     * Rebuilds the full message (copy, signed approval link) from a stored
     * delivery payload, so a send deferred by quiet hours carries the same
     * content as an immediate one, rendered against the current step (RV-BUG-16).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, User $notifiable, string $fallbackKind = 'update'): self
    {
        $kind = (string) ($payload['kind'] ?? $fallbackKind);
        $title = trim((string) ($payload['title'] ?? ''));
        $reference = trim((string) ($payload['reference_number'] ?? ''));

        $actionUrl = null;
        if (isset($payload['submission_id']) && is_numeric($payload['submission_id'])) {
            $actionUrl = route('submissions.show', $payload['submission_id']);
        }

        $enum = NotificationKind::tryFrom($kind);
        if ($enum !== null) {
            $submission = isset($payload['submission_id'])
                ? Submission::query()->find($payload['submission_id'])
                : null;
            $context = $submission
                ? NotificationContext::forSubmission($submission, $notifiable, [
                    'decision' => isset($payload['decision']) ? (string) $payload['decision'] : null,
                ])
                : [
                    'recipient_name' => $notifiable->name,
                    'reference_number' => $reference !== '' ? $reference : null,
                    'title' => $title !== '' ? $title : null,
                    'submission_url' => $actionUrl,
                ];
            $copy = app(NotificationCopy::class)->render($enum, $context);

            return new self(
                kind: $kind,
                subject: $copy->subject,
                lines: $copy->lines,
                actionUrl: $copy->actionUrl ?? $actionUrl,
                actionLabel: $copy->actionLabel,
                payload: $payload,
            );
        }

        $subject = trim(($reference !== '' ? '['.$reference.'] ' : '').($title !== '' ? $title : str_replace('_', ' ', $kind)));

        return new self(
            kind: $kind,
            subject: $subject !== '' ? $subject : $kind,
            lines: $title !== '' ? ['Title: '.$title] : [],
            actionUrl: $actionUrl,
            actionLabel: $actionUrl === null ? null : 'Open request',
            payload: $payload,
        );
    }

    public function textBody(): string
    {
        $parts = array_filter([$this->subject, ...$this->lines, $this->actionUrl]);

        return implode("\n", $parts);
    }
}
