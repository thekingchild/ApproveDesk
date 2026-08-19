<?php

namespace App\Enums;

enum GroupCompletion: string
{
    case FirstResponse = 'first_response';
    case All = 'all';
    case Quorum = 'quorum';

    public static function fromStepType(StepType $type): ?self
    {
        return match ($type) {
            StepType::Sequential => self::FirstResponse,
            StepType::Parallel => self::All,
            StepType::Quorum => self::Quorum,
            default => null,
        };
    }
}
