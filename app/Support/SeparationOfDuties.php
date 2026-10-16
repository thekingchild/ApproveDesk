<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;

/**
 * Separation of duties: a requester may not approve, decide, or receive a
 * delegation for their own request (RV-ENG-01). On by default; an
 * administrator may switch it off under Approval settings.
 */
final class SeparationOfDuties
{
    public const SETTING = 'approvals.block_self_approval';

    public static function enabled(): bool
    {
        return Setting::get(self::SETTING, '1') === '1';
    }

    public static function forbids(User $user, ?Submission $submission): bool
    {
        return $submission !== null
            && self::enabled()
            && (int) $submission->submitter_id === (int) $user->id;
    }
}
