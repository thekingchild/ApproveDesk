<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by an optional channel driver so the caller can record the real
 * outcome of a send: a provider refusal is Failed (and may be retried), a
 * message the channel cannot carry is Skipped (RV-BUG-15).
 */
final class NotificationDeliveryException extends RuntimeException
{
    private function __construct(string $message, public readonly bool $skipped)
    {
        parent::__construct($message);
    }

    public static function failed(string $reason): self
    {
        return new self($reason, false);
    }

    public static function skipped(string $reason): self
    {
        return new self($reason, true);
    }
}
