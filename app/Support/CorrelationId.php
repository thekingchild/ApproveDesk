<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    private static ?string $current = null;

    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    public static function current(): string
    {
        return self::$current ??= self::generate();
    }

    public static function set(string $id): void
    {
        self::$current = $id;
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    public static function accept(?string $candidate): string
    {
        if (is_string($candidate) && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $candidate) === 1) {
            return $candidate;
        }

        return self::generate();
    }
}
