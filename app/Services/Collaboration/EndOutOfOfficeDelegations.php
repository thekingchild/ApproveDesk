<?php

namespace App\Services\Collaboration;

use App\Models\Delegation;
use App\Models\OutOfOffice;
use App\Models\User;
use App\Services\WorkflowEngine;

/**
 * Out-of-office fallbacks are created as delegations ending at the schedule's
 * end. When the schedule is deleted or shortened, hand those steps back to the
 * person who is no longer away (RV-ENG-12).
 */
final class EndOutOfOfficeDelegations
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function execute(OutOfOffice $schedule, User $actor): int
    {
        if ($schedule->fallback_user_id === null) {
            return 0;
        }

        $delegations = Delegation::query()
            ->where('delegator_id', $schedule->user_id)
            ->where('delegate_id', $schedule->fallback_user_id)
            ->where('expires_at', $schedule->ends_at)
            ->where('expires_at', '>', now())
            ->get();

        foreach ($delegations as $delegation) {
            $this->engine->revokeDelegation($delegation, $actor, 'Out of office ended early.');
        }

        return $delegations->count();
    }
}
