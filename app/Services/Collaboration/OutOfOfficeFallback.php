<?php

namespace App\Services\Collaboration;

use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\OutOfOffice;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\WorkflowEngine;
use Illuminate\Support\Facades\Log;

final class OutOfOfficeFallback
{
    public function __construct(private readonly WorkflowEngine $engine) {}

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

    public function applyActiveSchedules(): int
    {
        $applied = 0;

        OutOfOffice::query()
            ->active()
            ->with('user')
            ->each(function (OutOfOffice $schedule) use (&$applied): void {
                $user = $schedule->user;
                if ($user === null || ! $user->is_active) {
                    return;
                }

                WorkflowInstance::query()
                    ->where('status', WorkflowStatus::Active->value)
                    ->with(['currentStep', 'submission'])
                    ->each(function (WorkflowInstance $instance) use ($user, &$applied): void {
                        $before = $instance->delegations()->count();
                        $this->delegateIfAway($instance, $user);
                        if ($instance->delegations()->count() > $before) {
                            $applied++;
                        }
                    });
            });

        return $applied;
    }

    private function delegateIfAway(WorkflowInstance $instance, User $assignee): void
    {
        $schedule = $assignee->activeOutOfOffice();
        if ($schedule === null || $schedule->fallback_user_id === null) {
            return;
        }

        $fallback = $schedule->fallback;
        if ($fallback === null || ! $fallback->is_active) {
            return;
        }

        $step = $instance->currentStep;
        if ($step === null || ! $step->allow_delegation) {
            return;
        }

        if (! $step->assignedUsers($instance->submission)->contains('id', $assignee->id)) {
            return;
        }

        try {
            $this->engine->delegate(
                $instance,
                $assignee,
                $fallback,
                $schedule->ends_at,
                trim((string) $schedule->message) !== ''
                    ? $schedule->message
                    : 'Out of office until '.$schedule->ends_at->toIso8601String(),
                now(),
                $step->id,
            );
        } catch (WorkflowException $exception) {
            Log::info('Out-of-office fallback skipped', [
                'instance_id' => $instance->id,
                'user_id' => $assignee->id,
                'fallback_user_id' => $fallback->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
