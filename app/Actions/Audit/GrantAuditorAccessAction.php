<?php

namespace App\Actions\Audit;

use App\Models\AuditorAccess;
use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class GrantAuditorAccessAction
{
    public function execute(User $grantor, User $auditor, CarbonInterface $startsAt, CarbonInterface $endsAt, string $reason): AuditorAccess
    {
        if (! $auditor->hasRole('auditor')) {
            throw new InvalidArgumentException('Only an auditor account can receive a time-bounded grant.');
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('The end of the grant must be after the start.');
        }

        return AuditorAccess::query()->create([
            'user_id' => $auditor->id,
            'granted_by' => $grantor->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $reason,
            'scopes' => ['read', 'evidence'],
        ]);
    }
}
