<?php

namespace App\Enums;

enum NotificationKind: string
{
    case ActionRequired = 'action_required';
    case Decided = 'decided';
    case Nudge = 'nudge';
    case Mention = 'mention';
    case Comment = 'comment';
    case WatcherStatus = 'watcher_status';
    case Delegated = 'delegated';
    case Digest = 'digest';

    public function label(): string
    {
        return match ($this) {
            self::ActionRequired => 'Action required',
            self::Decided => 'Decision',
            self::Nudge => 'Reminder',
            self::Mention => 'Mention',
            self::Comment => 'Comment',
            self::WatcherStatus => 'Follower update',
            self::Delegated => 'Delegation',
            self::Digest => 'Digest',
        };
    }

    public function isCritical(): bool
    {
        return $this === self::ActionRequired;
    }

    /**
     * @return list<string>
     */
    public function mergeFields(): array
    {
        $base = ['recipient_name', 'reference_number', 'title', 'submitter_name', 'priority', 'submission_url'];

        return match ($this) {
            self::ActionRequired => [...$base, 'approval_url'],
            self::Decided, self::WatcherStatus => [...$base, 'decision', 'comment'],
            self::Nudge, self::Comment, self::Mention => [...$base, 'comment'],
            self::Delegated => [...$base, 'comment'],
            self::Digest => ['recipient_name', 'count'],
        };
    }

    /**
     * @return list<string>
     */
    public function whatsappSlots(): array
    {
        return match ($this) {
            self::ActionRequired => ['recipient_name', 'reference_number', 'title', 'submitter_name', 'approval_url'],
            self::Digest => ['recipient_name', 'count'],
            default => ['recipient_name', 'reference_number', 'title', 'submission_url'],
        };
    }
}
