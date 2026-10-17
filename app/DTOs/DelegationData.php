<?php

namespace App\DTOs;

use App\Models\User;
use Carbon\CarbonInterface;

/** A request to hand the current step to someone else, for WorkflowEngine::delegate (RV-ARC-13). */
final readonly class DelegationData
{
    /**
     * @param  CarbonInterface|null  $startsAt  null starts now
     * @param  int|null  $expectedStepId  the step the delegator was looking at; refused if the request moved on
     */
    public function __construct(
        public User $delegator,
        public User $delegate,
        public CarbonInterface $expiresAt,
        public ?string $comment = null,
        public ?CarbonInterface $startsAt = null,
        public ?int $expectedStepId = null,
    ) {}
}
