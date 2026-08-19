<?php

namespace App\Enums;

enum StepType: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';
    case Quorum = 'quorum';
    case Conditional = 'conditional';
    case Auto = 'auto';
    case Notification = 'notification';

    public function isAutomatic(): bool
    {
        return in_array($this, [self::Conditional, self::Auto, self::Notification], true);
    }

    public function canvasKind(): string
    {
        return match ($this) {
            self::Conditional => 'condition',
            self::Auto => 'automatic',
            self::Notification => 'notification',
            default => 'approval',
        };
    }
}
