<?php

namespace App\Services\Collaboration;

use App\Models\Setting;
use App\Services\Workflow\BusinessCalendar;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class QuietHours
{
    /**
     * @return array{start: string, end: string}|null
     */
    public function window(): ?array
    {
        $raw = Setting::get('nudge.quiet_hours');
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $start = BusinessCalendar::normalizeTime(isset($decoded['start']) ? (string) $decoded['start'] : null);
        $end = BusinessCalendar::normalizeTime(isset($decoded['end']) ? (string) $decoded['end'] : null);

        if ($start === null || $end === null || $start === $end) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    public function isQuiet(?CarbonInterface $at = null): bool
    {
        $window = $this->window();
        if ($window === null) {
            return false;
        }

        $time = ($at ?? now())->format('H:i');

        if (strcmp($window['start'], $window['end']) < 0) {
            return strcmp($time, $window['start']) >= 0 && strcmp($time, $window['end']) < 0;
        }

        return strcmp($time, $window['start']) >= 0 || strcmp($time, $window['end']) < 0;
    }

    public function nextOpenAt(?CarbonInterface $at = null): CarbonInterface
    {
        $at ??= now();
        $window = $this->window();
        if ($window === null || ! $this->isQuiet($at)) {
            return $at;
        }

        [$hour, $minute] = array_map('intval', explode(':', $window['end']));
        $endToday = $at->copy()->setTime($hour, $minute, 0);

        if (strcmp($window['start'], $window['end']) < 0) {
            return $endToday->greaterThan($at) ? $endToday : $endToday->addDay();
        }

        $time = $at->format('H:i');
        if (strcmp($time, $window['start']) >= 0) {
            return $endToday->addDay();
        }

        return $endToday;
    }

    public static function encode(?string $start, ?string $end): ?string
    {
        if ($start === null && $end === null) {
            return null;
        }

        if (($start ?? '') === '' && ($end ?? '') === '') {
            return null;
        }

        $normalizedStart = BusinessCalendar::normalizeTime($start);
        $normalizedEnd = BusinessCalendar::normalizeTime($end);

        if ($normalizedStart === null || $normalizedEnd === null) {
            throw new InvalidArgumentException('Quiet hours must be a matching start and end time in HH:MM.');
        }

        if ($normalizedStart === $normalizedEnd) {
            throw new InvalidArgumentException('Quiet hours start and end cannot be the same time.');
        }

        return json_encode(['start' => $normalizedStart, 'end' => $normalizedEnd], JSON_THROW_ON_ERROR);
    }
}
