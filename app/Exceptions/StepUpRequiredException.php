<?php

namespace App\Exceptions;

use App\Enums\StepUpStatus;

/** A sensitive save was refused until the person re-confirms who they are (RV-SEC-16). */
final class StepUpRequiredException extends DomainException
{
    private function __construct(public readonly StepUpStatus $status, string $message)
    {
        parent::__construct($message);
    }

    public static function for(StepUpStatus $status): self
    {
        return new self($status, $status === StepUpStatus::NeedsPassword
            ? __('Confirm your password before saving analytics code.')
            : __('Confirm an authenticator or passkey in this session before saving analytics code.'));
    }
}
