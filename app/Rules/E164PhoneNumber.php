<?php

namespace App\Rules;

use App\Support\E164Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An optional phone number in international (E.164) form, after E164Phone::normalize(). */
final class E164PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! E164Phone::isValid($value)) {
            $fail(__('Enter a phone number in international format, such as +2348012345678.'));
        }
    }
}
