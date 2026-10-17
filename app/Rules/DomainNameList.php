<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Domain names, one per line or comma separated; blank entries are ignored. */
final class DomainNameList implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('Enter one domain name per line.'));

            return;
        }

        foreach (preg_split('/[\r\n,]+/', $value) ?: [] as $domain) {
            $domain = trim($domain);

            if ($domain !== '' && preg_match('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/', mb_strtolower($domain)) !== 1) {
                $fail(__('":domain" does not look like a domain name. One per line, e.g. example.com.', ['domain' => $domain]));

                return;
            }
        }
    }
}
