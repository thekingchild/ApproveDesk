<?php

namespace App\Support;

final class E164Phone
{
    public const PATTERN = '/^\+[1-9]\d{7,14}$/';

    public static function normalize(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $compact = preg_replace('/[\s().-]+/', '', $trimmed) ?? '';
        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        return $compact;
    }

    public static function isValid(?string $value): bool
    {
        $normalized = self::normalize($value);

        return $normalized !== null && preg_match(self::PATTERN, $normalized) === 1;
    }
}
