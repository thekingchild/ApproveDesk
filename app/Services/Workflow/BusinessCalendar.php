<?php

namespace App\Services\Workflow;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class BusinessCalendar
{
    private const TIME_RE = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private const MAX_JSON_BYTES = 2000;

    private const NEXT_START_MAX_DAYS = 4000;

    /**
     * Optional constructor overrides are a unit-test seam only.
     * The container resolves `new BusinessCalendar()` with no args.
     * An empty working-days override means "no working days" (does not fall back to Mon–Fri).
     * An empty working-hours override forces 24-hour mode without reading settings.
     *
     * @param  list<int>|null  $workingDaysOverride
     * @param  list<string>|null  $holidaysOverride
     * @param  array{start?: string, end?: string, by_day?: array<string, array{start: string, end: string}>}|null  $workingHoursOverride
     */
    public function __construct(
        private readonly ?array $workingDaysOverride = null,
        private readonly ?array $holidaysOverride = null,
        private readonly ?array $workingHoursOverride = null,
    ) {}

    /**
     * @return list<int> ISO-8601 weekdays (1 = Monday … 7 = Sunday)
     */
    public function workingDays(): array
    {
        if ($this->workingDaysOverride !== null) {
            return array_values(array_filter(
                $this->workingDaysOverride,
                fn (int $day): bool => $day >= 1 && $day <= 7,
            ));
        }

        $stored = Setting::get('calendar.working_days', '1,2,3,4,5');
        $days = array_values(array_filter(
            array_map('intval', explode(',', (string) $stored)),
            fn (int $day): bool => $day >= 1 && $day <= 7,
        ));

        return $days === [] ? [1, 2, 3, 4, 5] : $days;
    }

    /**
     * @return list<string> Y-m-d holiday dates
     */
    public function holidays(): array
    {
        if ($this->holidaysOverride !== null) {
            return array_values(array_filter(
                $this->holidaysOverride,
                fn (string $day): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1,
            ));
        }

        $stored = trim((string) Setting::get('calendar.holidays', ''));
        if ($stored === '') {
            return [];
        }

        return collect(preg_split('/[\s,]+/', $stored) ?: [])
            ->map(fn (string $day): string => trim($day))
            ->filter(fn (string $day): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Parsed office hours, or null for 24-hour-on-working-day mode.
     *
     * @return array{start: string, end: string, by_day: array<string, array{start: string, end: string}>}|null
     */
    public function workingHours(): ?array
    {
        if ($this->workingHoursOverride !== null) {
            if ($this->workingHoursOverride === []) {
                return null;
            }

            return $this->parseHoursDocument($this->workingHoursOverride, fromSettings: false);
        }

        $stored = Setting::get('calendar.working_hours');
        if ($stored === null || $stored === '' || $stored === 'null') {
            return null;
        }

        try {
            $decoded = json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->warnInvalidHours('unparsable JSON', $stored);

            return null;
        }

        if (! is_array($decoded)) {
            $this->warnInvalidHours('JSON is not an object', $stored);

            return null;
        }

        return $this->parseHoursDocument($decoded, fromSettings: true);
    }

    /**
     * Weekday ∈ working days and date ∉ holidays. Ignores office hours.
     */
    public function isWorkingDay(CarbonInterface $instant): bool
    {
        if (! in_array((int) $instant->isoWeekday(), $this->workingDays(), true)) {
            return false;
        }

        return ! in_array($instant->toDateString(), $this->holidays(), true);
    }

    /**
     * Counts toward a Business-hour SLA.
     * Working day, and (24h mode or time ∈ [start, end) for that weekday).
     */
    public function isWorking(CarbonInterface $instant): bool
    {
        if (! $this->isWorkingDay($instant)) {
            return false;
        }

        $hours = $this->workingHours();
        if ($hours === null) {
            return true;
        }

        $window = $this->windowOnDate(CarbonImmutable::parse($instant, $this->timezone()), $hours);
        if ($window === null) {
            return false;
        }

        return $instant->gte($window['start']) && $instant->lt($window['end']);
    }

    /**
     * Next SLA-countable interval start strictly after $cursor.
     */
    public function nextCountableStart(CarbonImmutable $cursor): CarbonImmutable
    {
        $hours = $this->workingHours();
        if ($hours === null) {
            throw new RuntimeException('Business calendar could not consume remaining seconds');
        }

        $date = $cursor->timezone($this->timezone())->startOfDay();

        for ($walks = 0; $walks < self::NEXT_START_MAX_DAYS; $walks++) {
            $window = $this->windowOnDate($date, $hours);
            if ($window !== null && $window['start']->gt($cursor)) {
                return $window['start'];
            }

            $date = $date->startOfDay()->addDay();
        }

        throw new RuntimeException('Business calendar could not consume remaining seconds');
    }

    public function addBusinessHours(CarbonInterface $start, int $hours): CarbonImmutable
    {
        $cursor = CarbonImmutable::parse($start, $this->timezone());
        $hours = max(0, $hours);

        if ($hours === 0) {
            return $cursor;
        }

        $windowSpec = $this->workingHours();
        if ($windowSpec === null) {
            return $this->addWorkingDaySeconds($cursor, $hours * 3600);
        }

        $remaining = $hours * 3600;
        $iterations = 0;
        $maxOuterIterations = max(10_000, $hours * 60 * 7 + 366);

        while ($remaining > 0) {
            $iterations++;
            if ($iterations > $maxOuterIterations) {
                throw new RuntimeException('Business calendar could not consume remaining seconds');
            }

            $window = $this->windowOnDate($cursor, $windowSpec);
            if ($window === null || $cursor->gte($window['end'])) {
                $cursor = $this->nextCountableStart($cursor);

                continue;
            }

            if ($cursor->lt($window['start'])) {
                $next = $window['start'];
                $cursor = $next->gt($cursor) ? $next : $this->nextCountableStart($cursor);

                continue;
            }

            $take = min($remaining, (int) $window['end']->timestamp - (int) $cursor->timestamp);
            if ($take <= 0) {
                $cursor = $this->nextCountableStart($cursor);

                continue;
            }

            $cursor = $cursor->addSeconds($take);
            $remaining -= $take;
        }

        return $cursor;
    }

    /**
     * 24-hour mode: count only time that falls on a working day. Judging each
     * whole hour by the day it ends on counted the last hour before a working
     * day (Sunday 23:00-24:00), so deadlines came up to an hour early
     * (RV-BUG-34).
     */
    private function addWorkingDaySeconds(CarbonImmutable $cursor, int $remaining): CarbonImmutable
    {
        $idleDays = 0;

        while ($remaining > 0) {
            $nextMidnight = $cursor->startOfDay()->addDay();

            if (! $this->isWorkingDay($cursor)) {
                if (++$idleDays > self::NEXT_START_MAX_DAYS) {
                    throw new RuntimeException('Business calendar could not consume remaining seconds');
                }

                $cursor = $nextMidnight;

                continue;
            }

            $idleDays = 0;
            $take = min($remaining, (int) $nextMidnight->timestamp - (int) $cursor->timestamp);
            $cursor = $cursor->addSeconds($take);
            $remaining -= $take;
        }

        return $cursor;
    }

    /**
     * @param  array<mixed>  $document
     * @return array{start: string, end: string, by_day: array<string, array{start: string, end: string}>}|null
     */
    private function parseHoursDocument(array $document, bool $fromSettings): ?array
    {
        $start = $this->normalizeTime(isset($document['start']) && is_string($document['start']) ? $document['start'] : null);
        $end = $this->normalizeTime(isset($document['end']) && is_string($document['end']) ? $document['end'] : null);

        if ($start === null || $end === null || strcmp($start, $end) >= 0) {
            if ($fromSettings && $document !== []) {
                $this->warnInvalidHours('invalid top-level window', null);
            }

            return null;
        }

        $byDay = [];
        $rawByDay = $document['by_day'] ?? [];
        if (is_array($rawByDay)) {
            foreach ($rawByDay as $key => $value) {
                $weekday = (string) $key;
                if (! in_array($weekday, ['1', '2', '3', '4', '5', '6', '7'], true) || ! is_array($value)) {
                    continue;
                }

                $dayStart = $this->normalizeTime(isset($value['start']) && is_string($value['start']) ? $value['start'] : null);
                $dayEnd = $this->normalizeTime(isset($value['end']) && is_string($value['end']) ? $value['end'] : null);
                if ($dayStart === null || $dayEnd === null || strcmp($dayStart, $dayEnd) >= 0) {
                    Log::warning('Invalid by_day entry on calendar.working_hours', ['weekday' => $weekday]);

                    continue;
                }

                $byDay[$weekday] = ['start' => $dayStart, 'end' => $dayEnd];
            }
        }

        return [
            'start' => $start,
            'end' => $end,
            'by_day' => $byDay,
        ];
    }

    /**
     * @param  array{start: string, end: string, by_day: array<string, array{start: string, end: string}>}  $hours
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function windowOnDate(CarbonImmutable $cursor, array $hours): ?array
    {
        if (! $this->isWorkingDay($cursor)) {
            return null;
        }

        $weekday = (string) $cursor->isoWeekday();
        $spec = $hours['by_day'][$weekday] ?? ['start' => $hours['start'], 'end' => $hours['end']];
        $date = $cursor->timezone($this->timezone())->startOfDay();

        return [
            'start' => $this->atTime($date, $spec['start']),
            'end' => $this->atTime($date, $spec['end']),
        ];
    }

    private function atTime(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $date->timezone($this->timezone())->setTime($hour, $minute, 0);
    }

    public static function normalizeTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $trimmed) === 1) {
            $trimmed = substr($trimmed, 0, 5);
        }

        return preg_match(self::TIME_RE, $trimmed) === 1 ? $trimmed : null;
    }

    public static function encodeHoursPayload(string $start, string $end, array $byDay): string
    {
        $payload = ['start' => $start, 'end' => $end];
        if ($byDay !== []) {
            $payload['by_day'] = $byDay;
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) <= self::MAX_JSON_BYTES) {
            return $encoded;
        }

        $withoutByDay = json_encode(['start' => $start, 'end' => $end], JSON_THROW_ON_ERROR);
        Log::warning('calendar.working_hours exceeded size cap; dropping by_day');

        return $withoutByDay;
    }

    private function timezone(): string
    {
        return (string) config('app.timezone');
    }

    private function warnInvalidHours(string $reason, ?string $raw): void
    {
        $context = ['key' => 'calendar.working_hours', 'reason' => $reason];
        if ($raw !== null && strlen($raw) <= 500) {
            $context['preview'] = $raw;
        }

        Log::warning('Invalid calendar.working_hours; using 24-hour working days', $context);
    }
}
