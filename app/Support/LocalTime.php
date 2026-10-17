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
    /** Cookie set by resources/js/viewer-timezone.js. */
    public const COOKIE = 'approvedesk_tz';

    /** Named display formats; views use these through <x-time> (RV-ARC-17). */
    public const FORMATS = [
        'date' => 'd M Y',
        'datetime' => 'd M Y H:i',
        'time' => 'H:i',
        'long' => 'D j M Y, H:i',
    ];

    public static function parse(string $value, ?string $browserTimezone): Carbon
    {
        $appTimezone = (string) config('app.timezone', 'UTC');
        $timezone = self::validTimezone($browserTimezone) ?? self::viewerTimezone();

        return Carbon::parse($value, $timezone)->setTimezone($appTimezone);
    }

    /**
     * Show a stored time in the same zone the form beside it reads times in,
     * so what you typed is what you see back (RV-ARC-17).
     */
    public static function display(CarbonInterface $at, ?string $browserTimezone, string $format = 'datetime'): string
    {
        return $at->copy()->setTimezone(self::label($browserTimezone))->format(self::FORMATS[$format] ?? $format);
    }

    /** A stored time in the viewer's zone, for screens without a time-zone-aware form. */
    public static function forViewer(CarbonInterface $at, string $format = 'datetime'): string
    {
        return self::display($at, null, $format);
    }

    /**
     * "Now" as a datetime-local input value in the viewer's zone, so a form's
     * default reads back as the same moment (RV-ARC-17).
     */
    public static function inputValue(CarbonInterface $at, ?string $browserTimezone = null): string
    {
        return $at->copy()->setTimezone(self::label($browserTimezone))->format('Y-m-d\\TH:i');
    }

    /** The viewer's remembered browser zone, or the installation's. */
    public static function viewerTimezone(): string
    {
        $cookie = request()->cookie(self::COOKIE);

        return self::validTimezone(is_string($cookie) ? $cookie : null) ?? (string) config('app.timezone', 'UTC');
    }

    /** The timezone the viewer's times are shown and read in. */
    public static function label(?string $browserTimezone): string
    {
        return self::validTimezone($browserTimezone) ?? self::viewerTimezone();
    }

    public static function validTimezone(?string $timezone): ?string
    {
        return $timezone !== null && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : null;
    }
}
