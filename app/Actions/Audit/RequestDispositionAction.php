<?php

namespace App\Actions\Audit;

use App\Enums\DispositionStatus;
use App\Enums\SubmissionStatus;
use App\Models\DispositionReview;
use App\Models\Submission;
use App\Models\User;
use InvalidArgumentException;

final class RequestDispositionAction
{
    /** Statuses retention treats as finished; only these can be disposed of (RV-BUG-26). */
    public const FINISHED = [
        SubmissionStatus::Approved,
        SubmissionStatus::Rejected,
        SubmissionStatus::Withdrawn,
    ];

    public function execute(User $actor, Submission $submission, string $notes = ''): DispositionReview
    {
        self::ensureFinished($submission);

        return DispositionReview::query()->create([
            'submission_id' => $submission->id,
            'status' => DispositionStatus::Pending,
            'requested_by' => $actor->id,
            'notes' => $notes !== '' ? $notes : null,
        ]);
    }

    public static function ensureFinished(Submission $submission): void
    {
        if (! in_array($submission->status, self::FINISHED, true)) {
            throw new InvalidArgumentException('Only a finished request (approved, rejected, or withdrawn) can be reviewed for disposal.');
        }
    }
}
