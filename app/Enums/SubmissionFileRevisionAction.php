<?php

namespace App\Enums;

enum SubmissionFileRevisionAction: string
{
    case Added = 'added';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Added => __('Added'),
            self::Removed => __('Removed'),
        };
    }
}
