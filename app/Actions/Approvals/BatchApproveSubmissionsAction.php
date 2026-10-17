<?php

namespace App\Actions\Approvals;

use App\DTOs\WorkflowActionData;
use App\Enums\WorkflowActionType;
use App\Exceptions\WorkflowException;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\ApproverWorkbench;
use App\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;

class BatchApproveSubmissionsAction
{
    public function __construct(
        private readonly ApproverWorkbench $workbench,
        private readonly WorkflowEngine $engine,
    ) {}

    /** @param array<int, int|string> $instanceIds */
    public function execute(array $instanceIds, User $approver): int
    {
        $ids = collect($instanceIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            throw new WorkflowException('Select at least one approval.');
        }

        return DB::transaction(function () use ($ids, $approver): int {
            $instances = WorkflowInstance::query()
                ->with(['currentStep', 'actions', 'delegations', 'submission'])
                ->whereKey($ids)
                ->orderBy('id')
                ->get();

            if ($instances->count() !== $ids->count()) {
                throw new WorkflowException('One or more selected approvals no longer exist.');
            }

            if ($instances->map(fn ($instance) => $instance->template_id.':'.$instance->current_step_id)->unique()->count() !== 1) {
                throw new WorkflowException('Batch approval requires requests at the same workflow step.');
            }

            foreach ($instances as $instance) {
                if (! $this->workbench->canAct($instance, $approver)) {
                    throw new WorkflowException('One or more selected approvals are no longer eligible.');
                }
            }

            foreach ($instances as $instance) {
                $this->engine->processAction($instance, new WorkflowActionData($approver, WorkflowActionType::Approve, expectedStepId: (int) $instance->current_step_id));
            }

            return $instances->count();
        });
    }
}
