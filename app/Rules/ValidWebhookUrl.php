<?php

namespace App\Rules;

use App\Support\OutboundDestination;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/** Rejects non-TLS and private or rebinding destinations before a delivery job can call them. */
final class ValidWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('The :attribute must be a valid URL.'));

            return;
        }

        try {
            app(OutboundDestination::class)->validate($value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
