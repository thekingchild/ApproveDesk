<?php

namespace App\Jobs;

use App\Enums\WorkflowStatus;
use App\Models\WorkflowInstance;
use App\Services\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessEscalation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One copy queued or running at a time; the schedule's withoutOverlapping()
     * only guards dispatch, so copies piled up when the worker lagged (RV-OPS-02).
     * The lock lapses after this many seconds if a worker is killed mid-job.
     */
    public int $uniqueFor = 900;

    public function handle(): void
    {
        WorkflowInstance::query()
            ->where('status', WorkflowStatus::Active)
            ->whereNotNull('current_step_id')
            ->whereNotNull('step_started_at')
            ->whereHas('currentStep', fn ($query) => $query
                ->whereNotNull('timeout_hours')
                ->whereNotNull('timeout_action'))
            ->with('currentStep')
            ->chunkById(100, function ($instances): void {
                foreach ($instances as $instance) {
                    try {
                        if ($instance->isOverdue()) {
                            app(WorkflowEngine::class)->processTimeout($instance);
                        }
                    } catch (Throwable $exception) {
                        Log::error('Escalation processing failed for instance', [
                            'instance_id' => $instance->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });
    }
}
