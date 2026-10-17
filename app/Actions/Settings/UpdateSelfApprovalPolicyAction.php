<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Services\Workflow\SeparationOfDuties;

final readonly class UpdateSelfApprovalPolicyAction
{
    public function execute(bool $blocked): void
    {
        Setting::put(SeparationOfDuties::SETTING, $blocked ? '1' : '0');
    }
}
