<?php

namespace App\Actions\Settings;

use App\Actions\Collaboration\NudgeApproversAction;
use App\Models\Setting;
use App\Services\Collaboration\QuietHours;

final class UpdateNudgeSettingsAction
{
    public function execute(
        ?string $quietHoursStart = null,
        ?string $quietHoursEnd = null,
        ?int $intervalMinutes = null,
        ?int $maxPerDay = null,
    ): void {
        if ($quietHoursStart !== null || $quietHoursEnd !== null) {
            Setting::put(
                'nudge.quiet_hours',
                QuietHours::encode($quietHoursStart ?? '', $quietHoursEnd ?? ''),
            );
        }

        if ($intervalMinutes !== null) {
            Setting::put(
                'nudge.rate_limit_minutes',
                (string) max(1, min(1440, $intervalMinutes)),
            );
        }

        if ($maxPerDay !== null) {
            Setting::put(
                'nudge.max_per_day',
                (string) max(1, min(50, $maxPerDay)),
            );
        } elseif (Setting::get('nudge.max_per_day') === null) {
            Setting::put('nudge.max_per_day', (string) NudgeApproversAction::DEFAULT_MAX_PER_DAY);
        }
    }
}
