<?php

namespace App\Actions\Audit;

use App\Enums\DispositionStatus;
use App\Models\DispositionReview;
use App\Models\Submission;
use App\Models\User;
use App\Services\Workflow\SeparationOfDuties;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReviewDispositionAction
{
    /**
     * Approved dispositions feed retention deletion, so a review is decided once,
     * under a lock, and — under separation of duties — never by the person who
     * requested it (RV-SEC-19).
     */
    public function execute(User $actor, DispositionReview $review, DispositionStatus $status, string $notes = ''): DispositionReview
    {
        if ($status === DispositionStatus::Pending) {
            throw new InvalidArgumentException('Choose approve or reject.');
        }

        return DB::transaction(function () use ($actor, $review, $status, $notes): DispositionReview {
            /** @var DispositionReview $locked */
            $locked = DispositionReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== DispositionStatus::Pending) {
                throw new InvalidArgumentException('This disposition review has already been decided.');
            }

            if (SeparationOfDuties::enabled() && (int) $locked->requested_by === (int) $actor->id) {
                throw new InvalidArgumentException('A different person must review a disposition you requested.');
            }

            // Reviews opened before RV-BUG-26, or a request reopened since, must not be approved for disposal.
            if ($status === DispositionStatus::Approved) {
                $submission = Submission::withTrashed()->find($locked->submission_id);
                if ($submission === null) {
                    throw new InvalidArgumentException('That request no longer exists.');
                }
                RequestDispositionAction::ensureFinished($submission);
            }

            $locked->forceFill([
                'status' => $status,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'notes' => $notes !== '' ? $notes : $locked->notes,
            ])->save();

            return $locked;
        });
    }
}
