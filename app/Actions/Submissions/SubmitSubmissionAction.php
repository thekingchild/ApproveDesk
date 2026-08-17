<?php

namespace App\Actions\Submissions;

use App\Events\SubmissionCreated;
use App\Models\Submission;
use App\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * Sends a draft or returned submission into review. A draft starts its
 * workflow instance for the first time; a returned submission resumes the
 * instance that was put on hold, preserving its place in the chain.
 */
final class SubmitSubmissionAction
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function execute(Submission $submission): Submission
    {
        return DB::transaction(function () use ($submission): Submission {
            $instance = $submission->workflowInstance()->first();

            if ($instance === null) {
                $submission->update(['status' => 'pending']);
                $instance = $this->engine->initialize($submission);
                event(new SubmissionCreated($submission, $instance));
            } else {
                $this->engine->resume($instance);
            }

            return $submission;
        });
    }
}
