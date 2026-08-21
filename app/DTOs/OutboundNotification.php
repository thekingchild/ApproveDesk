<?php

namespace App\DTOs;

use App\Models\User;
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

        $kind = (string) ($payload['kind'] ?? class_basename($notification));
        $title = trim((string) ($payload['title'] ?? ''));
        $reference = trim((string) ($payload['reference_number'] ?? ''));

        $subject = trim(($reference !== '' ? '['.$reference.'] ' : '').($title !== '' ? $title : str_replace('_', ' ', $kind)));

        $actionUrl = null;
        if (isset($payload['submission_id']) && is_numeric($payload['submission_id'])) {
            $actionUrl = route('submissions.show', $payload['submission_id']);
        }

        $lines = [];
        if ($title !== '') {
            $lines[] = 'Title: '.$title;
        }

        return new self(
            kind: $kind,
            subject: $subject !== '' ? $subject : $kind,
            lines: $lines,
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
