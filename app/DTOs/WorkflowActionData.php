<?php

namespace App\DTOs;

use App\Enums\WorkflowActionType;
use App\Models\User;

/**
 * One decision on a workflow step, as every entry point (request page, API,
 * email, chat, batch) hands it to WorkflowEngine::processAction (RV-ARC-13).
 */
final readonly class WorkflowActionData
{
    /**
     * @param  string|null  $outcomeKey  a custom outcome on the template version; decides $action
     * @param  int|null  $expectedStepId  the step the actor was looking at; refused if the request moved on
     */
    public function __construct(
        public User $actor,
        public WorkflowActionType $action,
        public ?string $comment = null,
        public ?string $outcomeKey = null,
        public ?int $expectedStepId = null,
    ) {}
}
