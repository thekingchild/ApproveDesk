<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\EmailApprovalLinks;
use App\Support\SecondFactorAssurance;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class UpdateEmailApprovalSecurityAction
{
    public function __construct(private SecondFactorAssurance $assurance) {}

    public function execute(bool $required, User $actor, Request $request): void
    {
        $isEnabling = $required && ! EmailApprovalLinks::requiresSecondFactor();

        if ($isEnabling && ! $actor->hasSatisfiedSecondFactor()) {
            throw new InvalidArgumentException('Add and confirm an authenticator or passkey on your account first.');
        }

        if ($isEnabling && ! $this->assurance->satisfied($request, $actor)) {
            throw new InvalidArgumentException('Verify your second factor in this browser session before turning this on.');
        }

        Setting::put('approvals.email_require_second_factor', $required ? '1' : '0');
    }
}
