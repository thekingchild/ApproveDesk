<?php

namespace App\Services\Workflow;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class BusinessCalendar
{
    /**
     * @return list<int> ISO-8601 weekdays (1 = Monday … 7 = Sunday)
     */
    public function workingDays(): array
    {
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

    public function isWorking(CarbonInterface $instant): bool
    {
        if (! in_array((int) $instant->isoWeekday(), $this->workingDays(), true)) {
            return false;
        }

        return ! in_array($instant->toDateString(), $this->holidays(), true);
    }

    public function addBusinessHours(CarbonInterface $start, int $hours): CarbonImmutable
    {
        $cursor = CarbonImmutable::parse($start);
        $remaining = max(0, $hours);

        while ($remaining > 0) {
            $cursor = $cursor->addHour();
            if ($this->isWorking($cursor)) {
                $remaining--;
            }
        }

        return $cursor;
    }
}
