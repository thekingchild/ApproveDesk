<?php

namespace App\Exceptions;

class WorkflowException extends DomainException
{
    public static function noTemplateFor(string $type): self
    {
        return new self(
            "No active workflow template matches submission type [{$type}].",
            __('No approval workflow is set up for this kind of request. Ask an administrator.'),
        );
    }

    public static function templateWithoutSteps(string $template): self
    {
        return new self(
            "Workflow template [{$template}] has no steps defined.",
            __('The approval workflow for this request has no steps. Ask an administrator.'),
        );
    }

    public static function instanceNotActive(string $status): self
    {
        return new self(
            "Workflow instance is [{$status}], not active — no further actions are accepted.",
            __('This request is no longer open for decisions.'),
        );
    }

    public static function actorNotAssigned(string $actor, string $step): self
    {
        return new self(
            "User [{$actor}] is not an assignee of the current step [{$step}].",
            __('You are not an approver on the current step (:step).', ['step' => $step]),
        );
    }

    public static function duplicateAction(string $actor): self
    {
        return new self(
            "User [{$actor}] has already acted on the current step.",
            __('You have already decided this step.'),
        );
    }

    public static function unsupportedStepType(string $stepType): self
    {
        return new self(
            "Step type [{$stepType}] is not implemented yet.",
            __('This workflow uses a step type that is not available. Ask an administrator.'),
        );
    }

    public static function invalidQuorum(string $step, ?int $required, int $assignees): self
    {
        $threshold = $required === null ? 'not set' : (string) $required;

        return new self(
            "Step [{$step}] has invalid quorum [{$threshold}]; it requires between 1 and {$assignees} active assignees.",
            __('The step ":step" needs more approvers than it has. Ask an administrator to fix the workflow.', ['step' => $step]),
        );
    }

    public static function delegationNotAllowed(string $reason): self
    {
        return new self(
            "Delegation is not allowed: {$reason}.",
            __('You cannot delegate this step: :reason.', ['reason' => $reason]),
        );
    }

    public static function invalidCondition(string $step, string $reason): self
    {
        return new self(
            "Conditional step [{$step}] is invalid: {$reason}.",
            __('The condition on the step ":step" is invalid. Ask an administrator to fix the workflow.', ['step' => $step]),
        );
    }

    public static function escalationNotAllowed(string $reason): self
    {
        return new self(
            "Escalation is not allowed: {$reason}.",
            __('This step cannot be escalated: :reason.', ['reason' => $reason]),
        );
    }

    public static function commentRequired(string $action): self
    {
        return new self(
            "A comment is required for the [{$action}] action.",
            __('Add a comment to :action.', ['action' => str_replace('_', ' ', $action)]),
        );
    }

    public static function unresolvedAssignees(string $step): self
    {
        return new self(
            "Step [{$step}] has no resolvable assignees for this request.",
            __('Nobody can approve the step ":step" for this request. Ask an administrator to check who approves it.', ['step' => $step]),
        );
    }

    public static function selfApprovalNotAllowed(): self
    {
        return new self(__('You submitted this request, so you cannot approve, decide, or take over a step on it.'));
    }

    public static function selfDelegationNotAllowed(): self
    {
        return new self(__('Delegation is not allowed: the requester cannot act on their own request.'));
    }

    public static function onlyApproverIsRequester(string $step): self
    {
        return new self(
            "Step [{$step}] would be approved only by the requester, which separation of duties does not allow. An administrator must change who approves this step.",
            __('The step ":step" would be approved only by the requester, which separation of duties does not allow. An administrator must change who approves this step.', ['step' => $step]),
        );
    }

    /** @param list<string> $labels */
    public static function missingRequiredFields(array $labels): self
    {
        return new self(__('Complete the required fields before submitting: :fields.', ['fields' => implode(', ', $labels)]));
    }

    public static function staleStep(): self
    {
        return new self(__('This request has moved on since you opened it. Reload to see its current step.'));
    }

    public static function unknownOutcome(string $outcome): self
    {
        return new self(
            "Workflow outcome [{$outcome}] is not defined for this version.",
            __('That decision option is no longer available. Reload the request.'),
        );
    }
}
