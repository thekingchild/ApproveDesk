<?php

namespace App\DTOs\Operations;

use Carbon\CarbonInterface;

final readonly class StuckWorkflow
{
    public function __construct(
        public int $instanceId,
        public int $submissionId,
        public string $reference,
        public string $reason,
        public ?CarbonInterface $stepStartedAt,
    ) {}
}
