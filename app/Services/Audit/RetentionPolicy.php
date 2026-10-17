<?php

namespace App\Services\Audit;

use App\Enums\DispositionStatus;
use App\Enums\SubmissionStatus;
use App\Models\LegalHold;
use App\Models\Submission;
use App\Support\RetentionSettings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which requests are due to be retired (RV-ARC-14). Retiring soft-deletes;
 * nothing is purged (RV-DATA-08). A legal hold always wins, and finished work
 * also needs an approved disposition review.
 */
final class RetentionPolicy
{
    /** Statuses that mean the workflow is over. */
    public const FINISHED = [
        SubmissionStatus::Approved,
        SubmissionStatus::Rejected,
        SubmissionStatus::Withdrawn,
    ];

    /** @return Builder<Submission> */
    public function draftsDue(): Builder
    {
        return Submission::query()
            ->where('status', SubmissionStatus::Draft)
            ->where('created_at', '<', now()->subDays(RetentionSettings::draftDays()))
            ->whereDoesntHave('legalHolds', $this->activeHold(...));
    }

    /** @return Builder<Submission> */
    public function finishedDue(): Builder
    {
        return Submission::query()
            ->whereIn('status', self::FINISHED)
            ->where('updated_at', '<', now()->subDays(RetentionSettings::completedDays()))
            ->whereDoesntHave('legalHolds', $this->activeHold(...))
            ->whereHas('dispositionReviews', fn ($query) => $query->where('status', DispositionStatus::Approved->value));
    }

    /** @param  Builder<LegalHold>  $holds */
    private function activeHold(Builder $holds): void
    {
        $holds->active();
    }
}
