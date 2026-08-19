<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Support\EmailApprovalLinks;
use InvalidArgumentException;

final class UpdateApprovalSettingsAction
{
    /**
     * @param  list<int>|null  $workingDays
     */
    public function execute(int $expirationHours, ?array $workingDays = null, ?string $holidays = null): void
    {
        if ($expirationHours < 1 || $expirationHours > EmailApprovalLinks::MAX_EXPIRATION_HOURS) {
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
    }
}
