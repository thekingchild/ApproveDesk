<?php

namespace App\Actions\Operations;

use App\Models\Setting;
use App\Services\Operations\OperationsMetrics;

final class RecordSchedulerHeartbeatAction
{
    public function __construct(private readonly OperationsMetrics $metrics) {}

    public function execute(): void
    {
        Setting::put('ops.scheduler_heartbeat_at', now()->toIso8601String());
        $this->metrics->recordSnapshot();
    }
}
