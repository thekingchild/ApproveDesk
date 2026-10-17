<?php

namespace App\Enums;

/** Where one step of a request's approval chain stands, as the request page shows it. */
enum TimelineStepState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Done = 'done';
    case Rejected = 'rejected';

    /** Tailwind class for the timeline dot. */
    public function dotClass(): string
    {
        return match ($this) {
            self::Pending => 'bg-zinc-400',
            self::Active => 'bg-blue-500',
            self::Done => 'bg-green-500',
            self::Rejected => 'bg-red-500',
        };
    }
}
