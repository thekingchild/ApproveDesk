<?php

namespace App\Services\Auth;

use App\Enums\StepUpStatus;
use App\Models\User;

/**
 * Step-up check for the most sensitive saves, such as owner custom code: a
 * password confirmation younger than auth.password_timeout, plus a second
 * factor confirmed in this session (RV-SEC-16, RV-ARC-10).
 */
final class StepUpConfirmation
{
    public function __construct(private readonly SecondFactorAssurance $secondFactor) {}

    public function status(User $user): StepUpStatus
    {
        $confirmedAt = session('auth.password_confirmed_at');

        if (! is_numeric($confirmedAt) || time() - (int) $confirmedAt > (int) config('auth.password_timeout', 10800)) {
            return StepUpStatus::NeedsPassword;
        }

        if (! $user->hasSatisfiedSecondFactor() || ! $this->secondFactor->satisfied(request(), $user)) {
            return StepUpStatus::NeedsSecondFactor;
        }

        return StepUpStatus::Satisfied;
    }
}
