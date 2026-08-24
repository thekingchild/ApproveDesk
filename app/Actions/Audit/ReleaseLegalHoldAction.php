<?php

namespace App\Actions\Audit;

use App\Models\LegalHold;
use App\Models\User;
use InvalidArgumentException;

final class ReleaseLegalHoldAction
{
    public function execute(User $actor, LegalHold $hold): LegalHold
    {
        if ($hold->released_at !== null) {
            throw new InvalidArgumentException('That legal hold is already released.');
        }

        $hold->forceFill([
            'released_at' => now(),
            'released_by' => $actor->id,
        ])->save();

        return $hold;
    }
}
