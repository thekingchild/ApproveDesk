<?php

namespace App\Listeners;

use App\Events\SubmissionCreated;
use App\Events\WorkflowAdvanced;
use App\Notifications\ActionRequiredNotification;

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

        if ($instance === null || $instance->status->value !== 'active') {
            return;
        }

        $step = $instance->currentStep()->first();

        if ($step === null) {
            return;
        }

        $step->assignedUsers($instance->submission)
            ->filter(fn ($user) => $user->is_active)
            ->each(fn ($user) => $user->notify(
                new ActionRequiredNotification($instance->submission()->first()),
            ));
    }
}
