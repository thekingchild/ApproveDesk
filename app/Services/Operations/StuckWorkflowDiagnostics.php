<?php

namespace App\Services\Operations;

use App\DTOs\Operations\StuckWorkflow;
use App\Enums\WorkflowStatus;
use App\Models\WorkflowInstance;
use App\Support\PerformanceBudgets;
use Illuminate\Support\Collection;

final class StuckWorkflowDiagnostics
{
    /**
     * @return Collection<int, StuckWorkflow>
     */
    public function find(int $limit = 50): Collection
    {
        $cutoff = now()->subHours(PerformanceBudgets::STUCK_AFTER_HOURS);

        return WorkflowInstance::query()
            ->with(['submission:id,reference_number,title'])
            ->where('status', WorkflowStatus::Active)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('current_step_id')
                    ->orWhere('step_started_at', '<=', $cutoff);
            })
            ->orderBy('step_started_at')
            ->limit($limit)
            ->get()
            ->map(fn (WorkflowInstance $instance): StuckWorkflow => new StuckWorkflow(
                instanceId: $instance->id,
                submissionId: (int) $instance->submission_id,
                reference: (string) ($instance->submission?->reference_number ?? $instance->id),
                reason: $instance->current_step_id === null
                    ? 'Active without a current step.'
                    : 'Current step has been waiting longer than the stuck threshold.',
                stepStartedAt: $instance->step_started_at,
            ));
    }

    public function count(): int
    {
        $cutoff = now()->subHours(PerformanceBudgets::STUCK_AFTER_HOURS);

        return WorkflowInstance::query()
            ->where('status', WorkflowStatus::Active)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('current_step_id')
                    ->orWhere('step_started_at', '<=', $cutoff);
            })
            ->count();
    }
}
