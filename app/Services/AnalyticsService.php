<?php

namespace App\Services;

use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Read-only, organization-wide workflow analytics.
 *
 * Every metric uses workflows started within the selected period as its cohort,
 * so headline cards, charts, bottlenecks, and exports reconcile.
 */
final class AnalyticsService
{
    /** @var list<int> */
    public const PERIODS = [7, 30, 90];

    /**
     * @return array{
     *   days: int,
     *   from: CarbonInterface,
     *   to: CarbonInterface,
     *   summary: array{started: int, active: int, resolved: int, approved: int, rejected: int, returned: int, approval_rate: float|null, returned_rate: float|null, sla_compliance: float|null, average_turnaround_minutes: int|null, median_turnaround_minutes: int|null, overdue: int},
     *   daily: list<array{date: string, label: string, started: int, resolved: int}>,
     *   bottlenecks: list<array{step_id: int, step: string, workflow: string, completed_samples: int, average_minutes: int|null, open: int, overdue: int, oldest_open_minutes: int|null, score_minutes: int}>,
     *   slowest_active: list<array{reference: string, title: string, step: string, started_at: CarbonInterface, elapsed_minutes: int, overdue: bool, submission_id: int}>,
     *   workload_by_approver: list<array{approver_id: int, approver: string, active: int, overdue: int}>
     * }
     */
    public function dashboard(int $days, ?User $user = null, ?int $requestTypeId = null): array
    {
        $days = $this->normalizeDays($days);
        $to = now()->endOfDay();
        $from = now()->subDays($days - 1)->startOfDay();

        $instances = $this->cohort($from, $to, $requestTypeId)->get();
        $resolved = $instances->filter(fn (WorkflowInstance $instance): bool => in_array(
            $instance->status,
            [WorkflowStatus::Completed, WorkflowStatus::Rejected],
            true,
        ));
        $approved = $resolved->where('status', WorkflowStatus::Completed)->count();
        $rejected = $resolved->where('status', WorkflowStatus::Rejected)->count();
        $active = $instances->whereIn('status', [WorkflowStatus::Active, WorkflowStatus::OnHold]);
        $overdue = $active->filter(fn (WorkflowInstance $instance): bool => $instance->isOverdue())->count();
        $returned = $instances->filter(fn (WorkflowInstance $instance): bool => $instance->actions->contains('action', WorkflowActionType::Return))->count();
        $turnarounds = $resolved
            ->filter(fn (WorkflowInstance $instance): bool => $instance->completed_at !== null)
            ->map(fn (WorkflowInstance $instance): int => (int) $instance->started_at->diffInMinutes($instance->completed_at, true))
            ->sort()
            ->values();

        return [
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'summary' => [
                'started' => $instances->count(),
                'active' => $active->count(),
                'resolved' => $resolved->count(),
                'approved' => $approved,
                'rejected' => $rejected,
                'returned' => $returned,
                'approval_rate' => $resolved->isEmpty() ? null : round(($approved / $resolved->count()) * 100, 1),
                'returned_rate' => $instances->isEmpty() ? null : round(($returned / $instances->count()) * 100, 1),
                'sla_compliance' => $active->isEmpty() ? null : round((($active->count() - $overdue) / $active->count()) * 100, 1),
                'average_turnaround_minutes' => $turnarounds->isEmpty() ? null : (int) round($turnarounds->average()),
                'median_turnaround_minutes' => $this->median($turnarounds),
                'overdue' => $overdue,
            ],
            'daily' => $this->dailySeries($instances, $from, $days),
            'bottlenecks' => $this->bottlenecks($instances),
            'slowest_active' => $this->slowestActive($instances),
            'workload_by_approver' => $this->workloadByApprover($active),
            'request_type_id' => $requestTypeId,
            'custom_fields' => $this->customFieldBreakdown($instances, $user),
        ];
    }

    /** @return Builder<WorkflowInstance> */
    private function cohort(CarbonInterface $from, CarbonInterface $to, ?int $requestTypeId = null): Builder
    {
        return WorkflowInstance::query()
            ->whereBetween('started_at', [$from, $to])
            ->when($requestTypeId !== null, fn (Builder $query) => $query->whereHas('submission.requestTypeVersion', fn (Builder $version) => $version->where('request_type_id', $requestTypeId)))
            ->with([
                'submission:id,reference_number,title,request_type_version_id,submitter_id',
                'submission.fieldValues.field',
                'submission.fieldValues.userValue:id,name',
                'submission.fieldValues.departmentValue:id,name',
                'currentStep:id,name,timeout_hours,assignee_type,assignee_ids',
                'template:id,name',
                'template.steps:id,template_id,step_order,name',
                'actions:id,instance_id,step_id,action,created_at',
                'delegations:id,instance_id,step_id,delegator_id,delegate_id,starts_at,expires_at',
                'delegations.delegate:id,name',
            ])
            ->orderBy('started_at');
    }

    /**
     * @param  Collection<int, WorkflowInstance>  $instances
     * @return list<array{key: string, label: string, values: list<array{value: string, count: int}>}>
     */
    private function customFieldBreakdown(Collection $instances, ?User $user): array
    {
        $groups = [];
        $access = app(RequestFieldAccess::class);

        foreach ($instances as $instance) {
            foreach ($instance->submission->fieldValues as $value) {
                $field = $value->field;

                if (! $field->is_reportable || ($field->is_sensitive && ($user === null || ! $access->canDiscoverSensitive($user)))) {
                    continue;
                }

                $display = $access->displayValue($value);
                $groups[$field->key] ??= ['key' => $field->key, 'label' => $field->label, 'counts' => []];
                $groups[$field->key]['counts'][$display] = ($groups[$field->key]['counts'][$display] ?? 0) + 1;
            }
        }

        return collect($groups)->map(function (array $group): array {
            arsort($group['counts']);

            return [
                'key' => $group['key'],
                'label' => $group['label'],
                'values' => collect($group['counts'])->map(fn (int $count, string $value): array => ['value' => $value, 'count' => $count])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, WorkflowInstance>  $instances
     * @return list<array{date: string, label: string, started: int, resolved: int}>
     */
    private function dailySeries(Collection $instances, CarbonInterface $from, int $days): array
    {
        $started = $instances->countBy(fn (WorkflowInstance $instance): string => $instance->started_at->toDateString());
        $resolved = $instances
            ->filter(fn (WorkflowInstance $instance): bool => $instance->completed_at !== null)
            ->countBy(fn (WorkflowInstance $instance): string => $instance->completed_at?->toDateString() ?? '');

        $series = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $from->copy()->addDays($offset);
            $key = $date->toDateString();
            $series[] = [
                'date' => $key,
                'label' => $date->format('M j'),
                'started' => (int) ($started[$key] ?? 0),
                'resolved' => (int) ($resolved[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @param  Collection<int, WorkflowInstance>  $instances
     * @return list<array{step_id: int, step: string, workflow: string, completed_samples: int, average_minutes: int|null, open: int, overdue: int, oldest_open_minutes: int|null, score_minutes: int}>
     */
    private function bottlenecks(Collection $instances): array
    {
        /** @var array<int, array{step_id: int, step: string, workflow: string, durations: list<int>, open_durations: list<int>, overdue: int}> $metrics */
        $metrics = [];

        foreach ($instances as $instance) {
            $cursor = $instance->started_at;
            $actionsByStep = $instance->actions->groupBy('step_id');

            foreach ($instance->template->steps as $step) {
                $stepActions = $actionsByStep->get($step->id, collect());
                $transition = $stepActions
                    ->filter(fn (WorkflowAction $action): bool => $this->isTransition($action->action))
                    ->last();
                $isCurrent = $instance->current_step_id === $step->id
                    && in_array($instance->status, [WorkflowStatus::Active, WorkflowStatus::OnHold], true);

                if ($transition === null && ! $isCurrent) {
                    continue;
                }

                $metrics[$step->id] ??= [
                    'step_id' => $step->id,
                    'step' => $step->name,
                    'workflow' => $instance->template->name,
                    'durations' => [],
                    'open_durations' => [],
                    'overdue' => 0,
                ];

                if ($transition !== null && $transition->created_at !== null) {
                    $metrics[$step->id]['durations'][] = (int) $cursor->diffInMinutes($transition->created_at, true);
                    $cursor = $transition->created_at;
                }

                if ($isCurrent) {
                    $openStartedAt = $instance->step_started_at ?? $cursor;
                    $metrics[$step->id]['open_durations'][] = (int) $openStartedAt->diffInMinutes(now(), true);
                    $metrics[$step->id]['overdue'] += $instance->isOverdue() ? 1 : 0;
                }
            }
        }

        return array_values(collect($metrics)
            ->map(function (array $metric): array {
                $average = $metric['durations'] === []
                    ? null
                    : (int) round(array_sum($metric['durations']) / count($metric['durations']));
                $oldestOpen = $metric['open_durations'] === [] ? null : max($metric['open_durations']);

                return [
                    'step_id' => $metric['step_id'],
                    'step' => $metric['step'],
                    'workflow' => $metric['workflow'],
                    'completed_samples' => count($metric['durations']),
                    'average_minutes' => $average,
                    'open' => count($metric['open_durations']),
                    'overdue' => $metric['overdue'],
                    'oldest_open_minutes' => $oldestOpen,
                    'score_minutes' => max($average ?? 0, $oldestOpen ?? 0),
                ];
            })
            ->sortByDesc('score_minutes')
            ->take(10)
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, WorkflowInstance>  $instances
     * @return list<array{reference: string, title: string, step: string, started_at: CarbonInterface, elapsed_minutes: int, overdue: bool, submission_id: int}>
     */
    private function slowestActive(Collection $instances): array
    {
        return array_values($instances
            ->filter(fn (WorkflowInstance $instance): bool => in_array($instance->status, [WorkflowStatus::Active, WorkflowStatus::OnHold], true))
            ->map(fn (WorkflowInstance $instance): array => [
                'reference' => $instance->submission->reference_number ?? '—',
                'title' => $instance->submission->title,
                'step' => $instance->current_step_id === null ? 'Awaiting transition' : $instance->currentStep->name,
                'started_at' => $instance->step_started_at ?? $instance->started_at,
                'elapsed_minutes' => (int) ($instance->step_started_at ?? $instance->started_at)->diffInMinutes(now(), true),
                'overdue' => $instance->isOverdue(),
                'submission_id' => $instance->submission_id,
            ])
            ->sortByDesc('elapsed_minutes')
            ->take(10)
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, WorkflowInstance>  $instances
     * @return list<array{approver_id: int, approver: string, active: int, overdue: int}>
     */
    private function workloadByApprover(Collection $instances): array
    {
        /** @var array<int, array{approver_id: int, approver: string, active: int, overdue: int}> $workload */
        $workload = [];

        foreach ($instances as $instance) {
            if ($instance->currentStep === null) {
                continue;
            }

            $approvers = $instance->currentStep->assignedUsers()->keyBy('id');
            $delegations = $instance->delegations->filter(fn ($delegation): bool => $delegation->step_id === $instance->current_step_id
                && $delegation->starts_at <= now()
                && $delegation->expires_at > now());

            foreach ($delegations as $delegation) {
                $approvers->forget($delegation->delegator_id);
                $approvers->put($delegation->delegate_id, $delegation->delegate);
            }

            foreach ($approvers as $approver) {
                $workload[$approver->id] ??= [
                    'approver_id' => $approver->id,
                    'approver' => $approver->name,
                    'active' => 0,
                    'overdue' => 0,
                ];
                $workload[$approver->id]['active']++;
                $workload[$approver->id]['overdue'] += $instance->isOverdue() ? 1 : 0;
            }
        }

        return array_values(collect($workload)
            ->sortBy([
                ['active', 'desc'],
                ['approver', 'asc'],
            ])
            ->take(10)
            ->all());
    }

    private function isTransition(WorkflowActionType $action): bool
    {
        return in_array($action, [
            WorkflowActionType::Approve,
            WorkflowActionType::Reject,
            WorkflowActionType::Return,
            WorkflowActionType::Escalate,
            WorkflowActionType::AutoApprove,
            WorkflowActionType::AutoReject,
            WorkflowActionType::ConditionMatched,
            WorkflowActionType::ConditionNotMatched,
        ], true);
    }

    /** @param SupportCollection<int, int> $values */
    private function median(SupportCollection $values): ?int
    {
        if ($values->isEmpty()) {
            return null;
        }

        $middle = intdiv($values->count(), 2);

        return $values->count() % 2 === 1
            ? (int) $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    private function normalizeDays(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : 30;
    }
}
