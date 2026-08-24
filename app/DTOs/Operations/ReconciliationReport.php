<?php

namespace App\DTOs\Operations;

final readonly class ReconciliationReport
{
    /**
     * @param  list<string>  $findings
     */
    public function __construct(
        public array $findings,
        public int $submittedWithoutInstance,
        public int $activeMissingStep,
        public int $deliveredWithRetryScheduled,
    ) {}

    public function ok(): bool
    {
        return $this->findings === [];
    }
}
