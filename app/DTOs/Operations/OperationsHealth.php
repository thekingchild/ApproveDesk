<?php

namespace App\DTOs\Operations;

use Carbon\CarbonInterface;

final readonly class OperationsHealth
{
    public function __construct(
        public ?CarbonInterface $schedulerHeartbeatAt,
        public bool $schedulerFresh,
        public int $pendingJobs,
        public int $failedJobs,
        public int $deadLetterWebhooks,
        public int $deadLetterIntegrations,
        public int $failedNotifications,
        public int $stuckWorkflows,
        public int $unhealthyConnectors,
        public int $failingWebhookEndpoints,
        public ?int $oldestPendingSeconds,
    ) {}
}
