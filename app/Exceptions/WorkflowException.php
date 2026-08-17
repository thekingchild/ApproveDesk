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
}
