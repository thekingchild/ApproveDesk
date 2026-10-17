<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Services\Workflow\BusinessCalendar;
use App\Support\ApprovalLinks;
use InvalidArgumentException;

final class UpdateApprovalSettingsAction
{
    /**
     * @param  list<int>|null  $workingDays
     */
    public function execute(
        int $expirationHours,
        ?array $workingDays = null,
        ?string $holidays = null,
        ?string $workingHoursStart = null,
        ?string $workingHoursEnd = null,
    ): void {
        if ($expirationHours < 1 || $expirationHours > ApprovalLinks::MAX_EXPIRATION_HOURS) {
            throw new InvalidArgumentException('Email approval link expiration is outside the supported range.');
        }

        Setting::put('approvals.email_link_expiration_hours', (string) $expirationHours);

        if ($workingDays !== null) {
            $days = array_values(array_filter($workingDays, fn (int $day): bool => $day >= 1 && $day <= 7));
            Setting::put('calendar.working_days', implode(',', $days === [] ? [1, 2, 3, 4, 5] : $days));
        }

        if ($holidays !== null) {
            Setting::put('calendar.holidays', trim($holidays));
        }

        $this->persistWorkingHours($workingHoursStart, $workingHoursEnd);
    }

    private function persistWorkingHours(?string $start, ?string $end): void
    {
        if ($start === null && $end === null) {
            return;
        }

        if ($start === '' && $end === '') {
            Setting::put('calendar.working_hours', null);

            return;
        }

        $normalizedStart = BusinessCalendar::normalizeTime($start);
        $normalizedEnd = BusinessCalendar::normalizeTime($end);

        if ($normalizedStart === null || $normalizedEnd === null) {
            throw new InvalidArgumentException('Office hours must be a matching start and end time in HH:MM.');
        }

        if (strcmp($normalizedStart, $normalizedEnd) >= 0) {
            throw new InvalidArgumentException('Office hours end must be after start on the same day.');
        }

        $byDay = [];
        $existing = app(BusinessCalendar::class)->workingHours();
        if ($existing !== null) {
            $byDay = $existing['by_day'];
        }

        Setting::put(
            'calendar.working_hours',
            BusinessCalendar::encodeHoursPayload($normalizedStart, $normalizedEnd, $byDay),
        );
    }
}
