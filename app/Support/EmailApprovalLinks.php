<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplateStep;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

final class EmailApprovalLinks
{
    public const DEFAULT_EXPIRATION_HOURS = 72;

    public const MAX_EXPIRATION_HOURS = 720;

    public static function expirationHours(): int
    {
        return max(1, min(
            self::MAX_EXPIRATION_HOURS,
            (int) Setting::get('approvals.email_link_expiration_hours', (string) self::DEFAULT_EXPIRATION_HOURS),
        ));
    }

    public static function requiresSecondFactor(): bool
    {
        return Setting::get('approvals.email_require_second_factor', '0') === '1';
    }

    public static function confirmationUrl(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $approver,
        ?CarbonInterface $expiresAt = null,
    ): string {
        return URL::temporarySignedRoute(
            'approvals.email.confirm',
            $expiresAt ?? now()->addHours(self::expirationHours()),
            ['instance' => $instance, 'step' => $step, 'approver' => $approver],
        );
    }

    public static function approvalUrl(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $approver,
        CarbonInterface $expiresAt,
    ): string {
        return URL::temporarySignedRoute(
            'approvals.email.approve',
            $expiresAt,
            ['instance' => $instance, 'step' => $step, 'approver' => $approver],
        );
    }
}
