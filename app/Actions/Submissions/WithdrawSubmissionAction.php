<?php

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;

/**
 * Submitter pulls a submission out of the workflow before a decision.
 * The instance is cancelled (not rejected) so the audit history stays
 * accurate and the reference numbers are never reused.
 */
final class WithdrawSubmissionAction
{
    public function execute(Submission $submission): void
    {
        DB::transaction(function () use ($submission): void {
            $submission->update(['status' => SubmissionStatus::Withdrawn]);

            $instance = $submission->workflowInstance()->first();
            $instance?->update([
                'status' => WorkflowStatus::Cancelled,
                'completed_at' => now(),
            ]);
        });
    }
}
