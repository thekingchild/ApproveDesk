<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Rejects non-TLS and obviously local endpoints before a delivery job can call them. */
final class ValidWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail(__('The :attribute must be a valid URL.'));

            return;
        }

        $parts = parse_url($value);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if ($scheme !== 'https') {
            $fail(__('Webhook endpoints must use HTTPS.'));

            return;
        }

        if (! is_string($host) || $host === '' || in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)
            || str_ends_with(strtolower($host), '.local')) {
            $fail(__('Webhook endpoints cannot target local addresses.'));

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $fail(__('Webhook endpoints cannot target private or reserved IP addresses.'));
        }
    }
}
