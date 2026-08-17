<?php

namespace App\Policies;

use App\Enums\SubmissionStatus;
use App\Models\Delegation;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;

class SubmissionPolicy
{
    public function view(User $user, Submission $submission): bool
    {
        if ($submission->submitter_id === $user->id || $user->can('submissions.view.all')) {
            return true;
        }

        // Approvers: anyone who has acted on, or is assigned to the current
        // step of, this submission's workflow may view it.
        $instance = $submission->workflowInstance()->first();

        if ($instance === null) {
            return false;
        }

        if ($instance->currentStep?->assignedUsers()->contains($user)) {
            return true;
        }

        if (Delegation::query()->active()
            ->where('instance_id', $instance->id)
            ->where('step_id', $instance->current_step_id)
            ->where('delegate_id', $user->id)
            ->exists()) {
            return true;
        }

        return WorkflowAction::query()
            ->where('instance_id', $instance->id)
            ->where('actor_id', $user->id)
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('submissions.create');
    }

    public function update(User $user, Submission $submission): bool
    {
        return $submission->submitter_id === $user->id
            && $submission->status === SubmissionStatus::Draft;
    }

    public function submit(User $user, Submission $submission): bool
    {
        return $submission->submitter_id === $user->id
            && in_array($submission->status, [SubmissionStatus::Draft, SubmissionStatus::Returned], true);
    }

    public function withdraw(User $user, Submission $submission): bool
    {
        return $submission->submitter_id === $user->id
            && in_array($submission->status, [SubmissionStatus::Pending, SubmissionStatus::InReview, SubmissionStatus::Returned], true);
    }

    public function comment(User $user, Submission $submission): bool
    {
        return $this->view($user, $submission);
    }

    public function downloadFiles(User $user, Submission $submission): bool
    {
        return $this->view($user, $submission);
    }
}
