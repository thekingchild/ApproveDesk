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

        if ($user->can('audit.view') && $user->hasActiveAuditorAccess()) {
            return true;
        }

        if ($submission->grantsFollowerAccessTo($user)) {
            return true;
        }

        // Approvers: anyone who has acted on, or is assigned to the current
        // step of, this submission's workflow may view it.
        $instance = $submission->workflowInstance()->first();

        if ($instance === null) {
            return false;
        }

        if ($instance->currentStep?->assignedUsers($instance->submission)->contains($user)) {
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
            && in_array($submission->status, [
                SubmissionStatus::Draft,
                SubmissionStatus::Returned,
                SubmissionStatus::AwaitingInformation,
            ], true);
    }

    public function submit(User $user, Submission $submission): bool
    {
        return $submission->submitter_id === $user->id
            && in_array($submission->status, [
                SubmissionStatus::Draft,
                SubmissionStatus::Returned,
                SubmissionStatus::AwaitingInformation,
            ], true);
    }

    public function withdraw(User $user, Submission $submission): bool
    {
        return $submission->submitter_id === $user->id
            && in_array($submission->status, [
                SubmissionStatus::Pending,
                SubmissionStatus::InReview,
                SubmissionStatus::Returned,
                SubmissionStatus::AwaitingInformation,
            ], true);
    }

    public function comment(User $user, Submission $submission): bool
    {
        return ! $user->isReadOnlyAuditor() && $this->view($user, $submission);
    }

    public function follow(User $user, Submission $submission): bool
    {
        return ! $user->isReadOnlyAuditor() && $this->view($user, $submission);
    }

    public function addWatcher(User $user, Submission $submission): bool
    {
        if ($user->isReadOnlyAuditor()) {
            return false;
        }
        if ($submission->submitter_id === $user->id || $user->can('submissions.view.all')) {
            return true;
        }
        if ($user->can('followers.manage') && $this->view($user, $submission)) {
            return true;
        }

        $instance = $submission->workflowInstance()->first();
        $step = $instance?->currentStep;

        return $step !== null
            && $step->assignedUsers($instance->submission)->contains($user);
    }

    public function nudge(User $user, Submission $submission): bool
    {
        return ! $user->isReadOnlyAuditor() && $this->view($user, $submission);
    }

    public function downloadFiles(User $user, Submission $submission): bool
    {
        return $this->view($user, $submission);
    }

    public function downloadEvidence(User $user, Submission $submission): bool
    {
        if ($user->can('settings.manage') || $user->can('submissions.view.all')) {
            return $this->view($user, $submission);
        }

        return $user->can('audit.view') && $user->hasActiveAuditorAccess() && $this->view($user, $submission);
    }

    public function approveViaEmail(User $user, Submission $submission): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $instance = $submission->workflowInstance()->first();
        $step = $instance?->currentStep;

        if ($instance === null || $step === null) {
            return false;
        }

        return $step->assignedUsers($instance->submission)->contains($user)
            || Delegation::query()->active()
                ->where('instance_id', $instance->id)
                ->where('step_id', $step->id)
                ->where('delegate_id', $user->id)
                ->exists();
    }
}
