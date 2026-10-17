<?php

namespace App\Actions\Settings;

use App\Enums\StepUpStatus;
use App\Exceptions\StepUpRequiredException;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\StepUpConfirmation;
use App\Support\Branding;
use Illuminate\Support\Facades\Gate;

/**
 * Owner analytics/custom code and the sites it may load from (ADR 0005). The
 * permission and the step-up both live here, not in the screen (RV-ARC-10).
 */
final class UpdateCustomCodeAction
{
    public function __construct(private readonly StepUpConfirmation $stepUp) {}

    /** Changing the code, or adding a site it may load from, needs a fresh step-up; removing sites does not. */
    public function requiresStepUp(?string $code, string $origins): bool
    {
        $existing = Setting::get('brand.custom_code');

        return $this->nullIfEmpty($code) !== $this->nullIfEmpty($existing)
            || array_diff(Branding::normalizeOrigins($origins), Branding::customCodeOrigins()) !== [];
    }

    /** @throws StepUpRequiredException when the change needs a password or second-factor confirmation first */
    public function execute(User $actor, ?string $code, string $origins): void
    {
        Gate::forUser($actor)->authorize('branding.custom_code.manage');

        if ($this->requiresStepUp($code, $origins)) {
            $status = $this->stepUp->status($actor);

            if ($status !== StepUpStatus::Satisfied) {
                throw StepUpRequiredException::for($status);
            }
        }

        Setting::put('brand.custom_code', $this->nullIfEmpty($code));
        // An explicit (possibly empty) list replaces host scraping for good (ADR 0005).
        Setting::put(Branding::CUSTOM_CODE_ORIGINS, implode("\n", Branding::normalizeOrigins($origins)));
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
