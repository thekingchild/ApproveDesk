<?php

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Services\WorkflowEngine;

/**
 * Submitter pulls a submission out of the workflow before a decision.
 * The instance is cancelled (not rejected) through the engine, so the
 * withdrawal is locked, recorded in the history, and reference numbers
 * are never reused.
 */
final readonly class WithdrawSubmissionAction
{
    public function __construct(private WorkflowEngine $engine) {}

    public function execute(Submission $submission, User $actor): void
    {
        $instance = $submission->workflowInstance()->first();

        if ($instance === null) {
            $submission->update(['status' => SubmissionStatus::Withdrawn]);

            return;
        }

        $this->engine->withdraw($instance, $actor);
    }
}
