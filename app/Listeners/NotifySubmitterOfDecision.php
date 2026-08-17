<?php

namespace App\Listeners;

use App\Enums\SubmissionStatus;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowInformationRequested;
use App\Events\WorkflowRejected;
use App\Events\WorkflowReturned;
use App\Notifications\SubmissionDecidedNotification;

/**
 * Notifies the submitter when their submission is approved, rejected, or
 * returned for revision.
 */
class NotifySubmitterOfDecision
{
    public function handle(WorkflowCompleted|WorkflowRejected|WorkflowReturned|WorkflowInformationRequested $event): void
    {
        $submission = $event->instance->submission()->first();

        if ($submission === null) {
            return;
        }

        $decision = match (true) {
            $event instanceof WorkflowCompleted => SubmissionStatus::Approved,
            $event instanceof WorkflowRejected => SubmissionStatus::Rejected,
            $event instanceof WorkflowReturned => SubmissionStatus::Returned,
            $event instanceof WorkflowInformationRequested => SubmissionStatus::AwaitingInformation,
        };

        $submission->submitter->notify(
            new SubmissionDecidedNotification($submission, $decision),
        );
    }
}
