<?php

namespace App\Rules;

use App\Support\Branding;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** HTTPS origins without a path, one per line or comma separated (ADR 0005). */
final class HttpsOriginList implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('Enter one HTTPS address per line.'));

            return;
        }

        foreach (preg_split('/[\r\n,]+/', $value) ?: [] as $origin) {
            $origin = rtrim(mb_strtolower(trim($origin)), '/');

            if ($origin !== '' && ! Branding::isOrigin($origin)) {
                $fail(__('":origin" is not an HTTPS origin. One per line, e.g. https://www.googletagmanager.com.', ['origin' => $origin]));

                return;
            }
        }
    }
}
