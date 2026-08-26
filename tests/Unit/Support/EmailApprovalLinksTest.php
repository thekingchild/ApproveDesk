<?php

namespace Tests\Unit\Support;

use App\Models\Setting;
use App\Support\EmailApprovalLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailApprovalLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_factor_requirement_is_enabled_only_by_exact_one(): void
    {
        $this->assertFalse(EmailApprovalLinks::requiresSecondFactor());

        Setting::put('approvals.email_require_second_factor', '1');
        $this->assertTrue(EmailApprovalLinks::requiresSecondFactor());

        foreach (['0', 'true', '01', ' 1'] as $value) {
            Setting::put('approvals.email_require_second_factor', $value);
            $this->assertFalse(EmailApprovalLinks::requiresSecondFactor());
        }
    }
}
