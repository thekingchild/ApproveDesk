<?php

namespace App\Listeners;

use App\Enums\SubmissionStatus;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowInformationRequested;
use App\Events\WorkflowRejected;
use App\Events\WorkflowReturned;
use App\Models\User;
use App\Notifications\WatcherStatusNotification;

class NotifyWatchersOfDecision
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

        $submission->watchers()
            ->with('user')
            ->where('notify_status', true)
            ->where('user_id', '!=', $submission->submitter_id)
            ->get()
            ->pluck('user')
            ->filter()
            ->each(fn (User $user) => $user->notify(new WatcherStatusNotification($submission, $decision)));
    }
}
