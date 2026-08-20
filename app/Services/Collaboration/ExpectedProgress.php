<?php

namespace App\Services\Collaboration;

use App\Enums\StepType;
use App\Enums\TimeoutCalendar;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplateStep;
use App\Services\SubmissionConditionEvaluator;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class ExpectedProgress
{
    public function __construct(private readonly SubmissionConditionEvaluator $conditions) {}

    /**
     * @return array{
     *     current_step: string|null,
     *     current_assignees: string,
     *     next_step: string|null,
     *     estimated_due_at: CarbonInterface|null,
     *     calendar: string|null,
     *     status: string|null
     * }|null
     */
    public function for(Submission $submission): ?array
    {
        $instance = $submission->workflowInstance;
        if ($instance === null) {
            return null;
        }

        $current = $instance->currentStep;
        $assignees = $current?->assignedUsers($instance->submission)
            ->pluck('name')
            ->filter()
            ->implode(', ') ?: __('Unassigned');

        return [
            'current_step' => $current?->name,
            'current_assignees' => $assignees,
            'next_step' => $this->nextStepName($instance),
            'estimated_due_at' => $instance->status === WorkflowStatus::Active ? $instance->deadlineAt() : null,
            'calendar' => $current?->timeout_hours !== null
                ? $current->timeoutCalendar()->value
                : null,
            'status' => $instance->status->value,
        ];
    }

    private function nextStepName(WorkflowInstance $instance): ?string
    {
        $current = $instance->currentStep;
        $steps = $instance->templateVersion?->steps ?? $instance->template->steps;

        if ($current === null || $steps->isEmpty()) {
            return null;
        }

        $ordered = $steps->sortBy('step_order')->values();
        $start = $ordered->search(fn (WorkflowTemplateStep $step): bool => $step->id === $current->id);
        if ($start === false) {
            return null;
        }

        $cursor = $start + 1;
        $guard = 0;

        while ($cursor < $ordered->count() && $guard++ < $ordered->count() + 2) {
            /** @var WorkflowTemplateStep $step */
            $step = $ordered[$cursor];

            if ($step->step_type === StepType::Conditional) {
                try {
                    $matched = $this->conditions->evaluate($instance->submission, $step->conditions ?? []);
                } catch (InvalidArgumentException) {
                    return $step->name;
                }

                $targetOrder = $matched
                    ? ($step->conditions['true_step'] ?? null)
                    : ($step->conditions['false_step'] ?? null);

                if ($targetOrder === null) {
                    return __('Complete');
                }

                $cursor = $ordered->search(
                    fn (WorkflowTemplateStep $candidate): bool => $candidate->step_order === (int) $targetOrder,
                );

                if ($cursor === false) {
                    return __('Complete');
                }

                continue;
            }

            if (in_array($step->step_type, [StepType::Notification, StepType::Auto], true)) {
                $cursor++;

                continue;
            }

            return $step->name;
        }

        return __('Complete');
    }

    public function calendarLabel(?string $calendar): string
    {
        return match ($calendar) {
            TimeoutCalendar::Business->value => __('business hours'),
            TimeoutCalendar::Elapsed->value => __('elapsed hours'),
            default => __('the step clock'),
        };
    }
}
