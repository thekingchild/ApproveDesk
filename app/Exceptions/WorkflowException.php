<?php

namespace App\Exceptions;

use RuntimeException;

class WorkflowException extends RuntimeException
{
    public static function noTemplateFor(string $type): self
    {
        return new self("No active workflow template matches submission type [{$type}].");
    }

    public static function templateWithoutSteps(string $template): self
    {
        return new self("Workflow template [{$template}] has no steps defined.");
    }

    public static function instanceNotActive(string $status): self
    {
        return new self("Workflow instance is [{$status}], not active — no further actions are accepted.");
    }

    public static function actorNotAssigned(string $actor, string $step): self
    {
        return new self("User [{$actor}] is not an assignee of the current step [{$step}].");
    }

    public static function duplicateAction(string $actor): self
    {
        return new self("User [{$actor}] has already acted on the current step.");
    }

    public static function unsupportedStepType(string $stepType): self
    {
        return new self("Step type [{$stepType}] is not implemented yet.");
    }

    public static function invalidQuorum(string $step, ?int $required, int $assignees): self
    {
        $threshold = $required === null ? 'not set' : (string) $required;

        return new self("Step [{$step}] has invalid quorum [{$threshold}]; it requires between 1 and {$assignees} active assignees.");
    }

    public static function delegationNotAllowed(string $reason): self
    {
        return new self("Delegation is not allowed: {$reason}.");
    }

    public static function invalidCondition(string $step, string $reason): self
    {
        return new self("Conditional step [{$step}] is invalid: {$reason}.");
    }

    public static function escalationNotAllowed(string $reason): self
    {
        return new self("Escalation is not allowed: {$reason}.");
    }

    public static function commentRequired(string $action): self
    {
        return new self("A comment is required for the [{$action}] action.");
    }

    public static function unresolvedAssignees(string $step): self
    {
        return new self("Step [{$step}] has no resolvable assignees for this request.");
    }

    public static function selfApprovalNotAllowed(): self
    {
        return new self('You submitted this request, so you cannot approve, decide, or take over a step on it.');
    }

    public static function selfDelegationNotAllowed(): self
    {
        return new self('Delegation is not allowed: the requester cannot act on their own request.');
    }

    public static function onlyApproverIsRequester(string $step): self
    {
        return new self("Step [{$step}] would be approved only by the requester, which separation of duties does not allow. An administrator must change who approves this step.");
    }

    public static function unknownOutcome(string $outcome): self
    {
        return new self("Workflow outcome [{$outcome}] is not defined for this version.");
    }
}
