<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * datetime-local inputs carry no timezone. Interpret them in the viewer's
 * browser timezone (when it is a valid IANA name) and convert to the
 * application timezone, so a delegation entered as 17:00 in Lagos ends at
 * 17:00 Lagos time on a UTC server (RV-BUG-03).
 */
final class LocalTime
{
    public static function parse(string $value, ?string $browserTimezone): Carbon
    {
        $appTimezone = (string) config('app.timezone', 'UTC');
        $timezone = self::validTimezone($browserTimezone) ?? $appTimezone;

        return Carbon::parse($value, $timezone)->setTimezone($appTimezone);
    }

    /**
     * Show a stored time in the same zone the form beside it reads times in,
     * so what you typed is what you see back (RV-ARC-17).
     */
    public static function display(CarbonInterface $at, ?string $browserTimezone, string $format = 'd M Y H:i'): string
    {
        return $at->copy()->setTimezone(self::label($browserTimezone))->format($format);
    }

    /** The timezone the viewer's times are shown and read in. */
    public static function label(?string $browserTimezone): string
    {
        return self::validTimezone($browserTimezone) ?? (string) config('app.timezone', 'UTC');
    }

    public static function validTimezone(?string $timezone): ?string
    {
        return $timezone !== null && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : null;
    }
}
