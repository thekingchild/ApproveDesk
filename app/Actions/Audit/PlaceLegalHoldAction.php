<?php

namespace App\Actions\Audit;

use App\Models\LegalHold;
use App\Models\Submission;
use App\Models\User;
use InvalidArgumentException;

final class PlaceLegalHoldAction
{
    public function execute(User $actor, Submission $submission, string $reason): LegalHold
    {
        if ($submission->legalHolds()->active()->exists()) {
            throw new InvalidArgumentException('This request already has an active legal hold.');
        }

        return LegalHold::query()->create([
            'submission_id' => $submission->id,
            'placed_by' => $actor->id,
            'reason' => $reason,
            'placed_at' => now(),
        ]);
    }
}
