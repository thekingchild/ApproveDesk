<?php

namespace App\Listeners;

use App\Enums\WorkflowStatus;
use App\Events\SubmissionCreated;
use App\Events\WorkflowAdvanced;
use App\Models\User;
use App\Notifications\ActionRequiredNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Whenever a submission enters review or advances to a new step, every
 * assignee of the now-active step gets an action-required notification
 * (email + in-app) through the queue.
 */
class NotifyCurrentApprovers
{
    public function handle(SubmissionCreated|WorkflowAdvanced $event): void
    {
        $instance = match (true) {
            $event instanceof SubmissionCreated => $event->instance ?? $event->submission->workflowInstance()->first(),
            default => $event->instance,
        };

        if ($instance === null || $instance->status !== WorkflowStatus::Active) {
            return;
        }

        $step = $instance->currentStep()->first();
        // Load the submission once, not once per approver (RV-PERF-15).
        $submission = $instance->submission()->first();

        if ($step === null || $submission === null) {
            return;
        }

        $approvers = $step->assignedUsers($submission)
            ->filter(fn (User $user): bool => $user->canReceiveNotifications());

        Notification::send($approvers, new ActionRequiredNotification($submission));
    }
}
