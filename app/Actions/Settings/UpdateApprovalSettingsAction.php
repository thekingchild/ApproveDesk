<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Support\EmailApprovalLinks;
use InvalidArgumentException;

final class UpdateApprovalSettingsAction
{
    public function execute(int $expirationHours): void
    {
        if ($expirationHours < 1 || $expirationHours > EmailApprovalLinks::MAX_EXPIRATION_HOURS) {
            throw new InvalidArgumentException('Email approval link expiration is outside the supported range.');
        }

        Setting::put('approvals.email_link_expiration_hours', (string) $expirationHours);
    }
}
