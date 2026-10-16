<?php

namespace App\Jobs;

use App\Enums\DispositionStatus;
use App\Enums\SubmissionStatus;
use App\Models\Setting;
use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ApplyRetentionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One copy queued or running at a time; the schedule's withoutOverlapping()
     * only guards dispatch, so copies piled up when the worker lagged (RV-OPS-02).
     * The lock lapses after this many seconds if a worker is killed mid-job.
     */
    public int $uniqueFor = 3600;

    public function handle(): void
    {
        $draftDays = max(1, (int) Setting::get('retention.draft_days', '90'));
        $completedDays = max(1, (int) Setting::get('retention.completed_days', '2555'));

        Submission::query()
            ->where('status', SubmissionStatus::Draft)
            ->where('created_at', '<', now()->subDays($draftDays))
            ->whereDoesntHave('legalHolds', fn ($query) => $query->active())
            ->each(fn (Submission $submission) => $submission->delete());

        Submission::query()
            ->whereIn('status', [
                SubmissionStatus::Approved,
                SubmissionStatus::Rejected,
                SubmissionStatus::Withdrawn,
            ])
            ->where('updated_at', '<', now()->subDays($completedDays))
            ->whereDoesntHave('legalHolds', fn ($query) => $query->active())
            ->whereHas('dispositionReviews', fn ($query) => $query->where('status', DispositionStatus::Approved->value))
            ->each(fn (Submission $submission) => $submission->delete());
    }
}
