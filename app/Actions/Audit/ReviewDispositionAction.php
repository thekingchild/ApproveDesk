<?php

namespace App\Actions\Audit;

use App\Enums\DispositionStatus;
use App\Models\DispositionReview;
use App\Models\User;
use InvalidArgumentException;

final class ReviewDispositionAction
{
    public function execute(User $actor, DispositionReview $review, DispositionStatus $status, string $notes = ''): DispositionReview
    {
        if ($status === DispositionStatus::Pending) {
            throw new InvalidArgumentException('Choose approve or reject.');
        }

        $review->forceFill([
            'status' => $status,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes' => $notes !== '' ? $notes : $review->notes,
        ])->save();

        return $review;
    }
}
