<?php

namespace App\Services\WorkflowTemplates;

use App\Enums\StepType;
use App\Enums\TimeoutAction;
use App\Models\WorkflowTemplateStep;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class WorkflowGraphValidator
{
    /**
     * @param  Collection<int, WorkflowTemplateStep>  $steps
     *
     * @throws ValidationException
     */
    public function validate(Collection $steps): void
    {
        $ordered = $steps->sortBy('step_order')->values();

        if ($ordered->isEmpty()) {
            throw ValidationException::withMessages([
                'steps' => __('Add at least one step before publishing.'),
            ]);
        }

        $errors = [];
        $orders = $ordered->pluck('step_order')->all();
        $max = (int) max($orders);

        foreach ($ordered as $index => $step) {
            $errors = array_merge($errors, $this->stepErrors($step, $index, $max));
        }

        $graph = $this->adjacency($ordered);
        $reachable = $this->reachable($graph, (int) $ordered->first()->step_order);

        foreach ($ordered as $step) {
            if (! isset($reachable[$step->step_order])) {
                $errors["steps.{$step->step_order}"][] = __('This step is unreachable from the start node.');
            }
        }

        if ($this->hasCycle($graph)) {
            $errors['steps'][] = __('This workflow contains a cycle. Every branch must move forward or end.');
        }

        $flat = [];
        foreach ($errors as $key => $messages) {
            $flat[$key] = is_array($messages) ? $messages[0] : $messages;
        }

        if ($flat !== []) {
            throw ValidationException::withMessages($flat);
        }
    }

    /**
     * @return list<string>
     */
    private function stepErrors(WorkflowTemplateStep $step, int $index, int $max): array
    {
        $prefix = "steps.{$index}";
        $errors = [];

        if ($step->step_type->isAutomatic() === false && $step->assignedUsers()->count() < 1) {
            $errors["{$prefix}.assigneeIds"] = __('This approval step has no active assignees.');
        }

        if ($step->step_type === StepType::Quorum) {
            $assignees = $step->assignedUsers()->unique('id')->count();
            if ($step->quorum_count === null || $step->quorum_count < 1 || $step->quorum_count > $assignees) {
                $errors["{$prefix}.quorumCount"] = __('Quorum must be between 1 and the number of active assignees.');
            }
        }

        if ($step->step_type === StepType::Conditional) {
            $true = $step->conditions['true_step'] ?? null;
            $false = $step->conditions['false_step'] ?? null;

            if (! array_key_exists('true_step', $step->conditions ?? []) || ! array_key_exists('false_step', $step->conditions ?? [])) {
                $errors["{$prefix}.trueStep"] = __('A condition step needs a true branch and a false branch, or completion.');
            }

            foreach (['true_step' => $true, 'false_step' => $false] as $label => $target) {
                if ($target !== null && (! is_int($target) || $target <= $step->step_order || $target > $max)) {
                    $errors["{$prefix}.".$label] = __('Each branch must target a later step or completion.');
                }
            }
        }

        $hasHours = $step->timeout_hours !== null;
        $hasAction = $step->timeout_action !== null;
        if ($hasHours !== $hasAction) {
            $errors["{$prefix}.timeoutHours"] = __('Set both an SLA duration and a timeout action, or leave both empty.');
        }

        if ($step->timeout_action === TimeoutAction::Escalate && $step->step_order >= $max) {
            $errors["{$prefix}.timeoutAction"] = __('The final step cannot escalate because there is no later step.');
        }

        return $errors;
    }

    /**
     * @param  Collection<int, WorkflowTemplateStep>  $steps
     * @return array<int, list<int|null>>
     */
    private function adjacency(Collection $steps): array
    {
        $byOrder = $steps->keyBy('step_order');
        $graph = [];

        foreach ($steps as $step) {
            $graph[$step->step_order] = $this->targets($step, $byOrder);
        }

        return $graph;
    }

    /**
     * @param  Collection<int, WorkflowTemplateStep>  $byOrder
     * @return list<int|null>
     */
    private function targets(WorkflowTemplateStep $step, Collection $byOrder): array
    {
        if ($step->step_type === StepType::Conditional) {
            return [
                $step->conditions['true_step'] ?? null,
                $step->conditions['false_step'] ?? null,
            ];
        }

        $next = $byOrder->get($step->step_order + 1);

        return [$next?->step_order];
    }

    /**
     * @param  array<int, list<int|null>>  $graph
     * @return array<int, true>
     */
    private function reachable(array $graph, int $start): array
    {
        $seen = [];
        $queue = [$start];

        while ($queue !== []) {
            $node = array_shift($queue);
            if ($node === null || isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($graph[$node] ?? [] as $next) {
                if ($next !== null) {
                    $queue[] = $next;
                }
            }
        }

        return $seen;
    }

    /**
     * @param  array<int, list<int|null>>  $graph
     */
    private function hasCycle(array $graph): bool
    {
        $state = [];

        $visit = function (int $node) use (&$visit, &$state, $graph): bool {
            $state[$node] = 'open';
            foreach ($graph[$node] ?? [] as $next) {
                if ($next === null) {
                    continue;
                }
                if (($state[$next] ?? null) === 'open') {
                    return true;
                }
                if (! isset($state[$next]) && $visit($next)) {
                    return true;
                }
            }
            $state[$node] = 'done';

            return false;
        };

        foreach (array_keys($graph) as $node) {
            if (! isset($state[$node]) && $visit($node)) {
                return true;
            }
        }

        return false;
    }
}
