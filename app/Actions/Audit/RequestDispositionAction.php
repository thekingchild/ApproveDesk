<?php

namespace App\Actions\Audit;

use App\Enums\DispositionStatus;
use App\Models\DispositionReview;
use App\Models\Submission;
use App\Models\User;

final class RequestDispositionAction
{
    public function execute(User $actor, Submission $submission, string $notes = ''): DispositionReview
    {
        return DispositionReview::query()->create([
            'submission_id' => $submission->id,
            'status' => DispositionStatus::Pending,
            'requested_by' => $actor->id,
            'notes' => $notes !== '' ? $notes : null,
        ]);
    }
}
