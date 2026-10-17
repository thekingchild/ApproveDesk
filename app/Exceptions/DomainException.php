<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A rule refused the request. getMessage() is for logs and tests and may name
 * internals; userMessage() is the translated text people see. Screens, the
 * API and chat channels show userMessage(), in one place where possible
 * (SupportDomainExceptions, bootstrap/app.php) rather than per call site
 * (RV-ARC-19, ADR 0006).
 */
abstract class DomainException extends RuntimeException
{
    public function __construct(string $message, private readonly ?string $userMessage = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function userMessage(): string
    {
        return $this->userMessage ?? $this->getMessage();
    }

    /** The text to show for any exception caught alongside domain exceptions. */
    public static function messageFor(Throwable $exception): string
    {
        return $exception instanceof self ? $exception->userMessage() : $exception->getMessage();
    }
}
