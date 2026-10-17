<?php

namespace App\Services\Collaboration;

use App\DTOs\DelegationData;
use App\Enums\AssigneeType;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Delegation;
use App\Models\OutOfOffice;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Workflow\AssignedStepConstraint;
use App\Services\WorkflowEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

final class OutOfOfficeFallback
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly AssignedStepConstraint $assignedSteps,
    ) {}

    public function applyToInstance(WorkflowInstance $instance): void
    {
        $instance->refresh();

        if ($instance->status !== WorkflowStatus::Active) {
            return;
        }

        $step = $instance->currentStep;
        if ($step === null || ! $step->allow_delegation) {
            return;
        }

        $assignees = $step->assignedUsers($instance->submission);

        foreach ($assignees as $assignee) {
            $this->delegateIfAway($instance, $assignee);
        }
    }

    /**
     * The 15-minute sweep. Only workflows where the away user could hold the
     * current step are loaded, steps already handed over are skipped, and each is still
     * checked against the step's resolved assignees before delegating
     * (RV-PERF-11: this used to visit every active workflow per away user).
     */
    public function applyActiveSchedules(): int
    {
        $applied = 0;

        // A user's latest active schedule wins, as in User::activeOutOfOffice().
        $schedules = OutOfOffice::query()
            ->active()
            ->whereNotNull('fallback_user_id')
            ->with(['user', 'fallback'])
            ->orderBy('id')
            ->get()
            ->keyBy('user_id');

        foreach ($schedules as $schedule) {
            $user = $schedule->user;
            if ($user === null || ! $user->is_active || $schedule->fallback === null || ! $schedule->fallback->is_active) {
                continue;
            }

            // Steps this user has already handed over are skipped without a query each.
            $handedOver = Delegation::query()->active()
                ->where('delegator_id', $user->id)
                ->get(['instance_id', 'step_id'])
                ->map(fn (Delegation $delegation): string => $delegation->instance_id.':'.$delegation->step_id)
                ->flip();

            $this->candidateInstances($user)->chunkById(100, function ($instances) use ($user, $schedule, $handedOver, &$applied): void {
                foreach ($instances as $instance) {
                    if ($handedOver->has($instance->id.':'.$instance->current_step_id)) {
                        continue;
                    }

                    if ($this->delegateIfAway($instance, $user, $schedule)) {
                        $applied++;
                    }
                }
            });
        }

        return $applied;
    }

    /** @return Builder<WorkflowInstance> */
    private function candidateInstances(User $user): Builder
    {
        return WorkflowInstance::query()
            ->where('status', WorkflowStatus::Active->value)
            ->whereHas('currentStep', fn (Builder $step) => $step->where('allow_delegation', true))
            ->where(function (Builder $assigned) use ($user): void {
                $this->assignedSteps->constrainInstances($assigned, $user)
                    // External resolvers cannot be matched in SQL; check those per instance.
                    ->orWhereHas('currentStep', fn (Builder $step) => $step->where('assignee_type', AssigneeType::External));
            })
            ->with(['currentStep', 'submission']);
    }

    private function delegateIfAway(WorkflowInstance $instance, User $assignee, ?OutOfOffice $schedule = null): bool
    {
        $schedule ??= $assignee->activeOutOfOffice();
        if ($schedule === null || $schedule->fallback_user_id === null) {
            return false;
        }

        $fallback = $schedule->fallback;
        if ($fallback === null || ! $fallback->is_active) {
            return false;
        }

        $step = $instance->currentStep;
        if ($step === null || ! $step->allow_delegation) {
            return false;
        }

        if (! $step->assignedUsers($instance->submission)->contains('id', $assignee->id)) {
            return false;
        }

        try {
            $this->engine->delegate(
                $instance,
                new DelegationData(
                    $assignee,
                    $fallback,
                    $schedule->ends_at,
                    trim((string) $schedule->message) !== ''
                        ? $schedule->message
                        : 'Out of office until '.$schedule->ends_at->toIso8601String(),
                    now(),
                    $step->id,
                ),
            );

            return true;
        } catch (WorkflowException $exception) {
            Log::info('Out-of-office fallback skipped', [
                'instance_id' => $instance->id,
                'user_id' => $assignee->id,
                'fallback_user_id' => $fallback->id,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
