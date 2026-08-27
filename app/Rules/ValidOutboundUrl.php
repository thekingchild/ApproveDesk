<?php

namespace App\Rules;

use App\Support\OutboundDestination;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class ValidOutboundUrl implements ValidationRule
{
    /**
     * @param  array{allow_private?: bool, allow_nonstandard_port?: bool}  $options
     */
    public function __construct(private readonly array $options = []) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            app(OutboundDestination::class)->validate($value, $this->options);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
