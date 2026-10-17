<?php

namespace App\Services\WorkflowTemplates;

use App\DTOs\WorkflowTemplateStepData;
use App\Enums\StepType;
use App\Enums\SubmissionPriority;
use App\Enums\SubmissionType;
use App\Models\Submission;
use App\Models\WorkflowTemplateStep;
use App\Services\SubmissionConditionEvaluator;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class WorkflowGraphSimulator
{
    public function __construct(private readonly SubmissionConditionEvaluator $conditions) {}

    /**
     * Trace steps still in an editor, without saving them first (RV-ARC-07).
     *
     * @param  array<int, WorkflowTemplateStepData>  $steps
     * @param  array{title?: string, priority?: string, type?: string, metadata?: array<string, mixed>}  $sample
     * @return list<array{order: int, name: string, kind: string, detail: string}>
     */
    public function traceDraft(array $steps, array $sample = []): array
    {
        return $this->trace(
            collect(array_values($steps))->map(fn (WorkflowTemplateStepData $step, int $index): WorkflowTemplateStep => $step->toUnsavedStep($index + 1)),
            $sample,
        );
    }

    /**
     * @param  Collection<int, WorkflowTemplateStep>  $steps
     * @param  array{title?: string, priority?: string, type?: string, metadata?: array<string, mixed>}  $sample
     * @return list<array{order: int, name: string, kind: string, detail: string}>
     */
    public function trace(Collection $steps, array $sample = []): array
    {
        $ordered = $steps->sortBy('step_order')->keyBy('step_order');
        $submission = $this->sampleSubmission($sample);
        $trace = [];
        $order = $ordered->keys()->sort()->first();
        $guard = 0;
        $max = $ordered->count() + 2;

        $trace[] = ['order' => 0, 'name' => 'Start', 'kind' => 'start', 'detail' => 'Simulation begins with the sample request.'];

        while ($order !== null && $guard++ < $max) {
            /** @var WorkflowTemplateStep|null $step */
            $step = $ordered->get($order);
            if ($step === null) {
                break;
            }

            if ($step->step_type === StepType::Conditional) {
                try {
                    $matched = $this->conditions->evaluate($submission, $step->conditions ?? []);
                } catch (InvalidArgumentException $exception) {
                    $trace[] = ['order' => $step->step_order, 'name' => $step->name, 'kind' => 'condition', 'detail' => 'Condition could not be evaluated: '.$exception->getMessage()];

                    return $trace;
                }

                $target = $matched ? ($step->conditions['true_step'] ?? null) : ($step->conditions['false_step'] ?? null);
                $trace[] = [
                    'order' => $step->step_order,
                    'name' => $step->name,
                    'kind' => 'condition',
                    'detail' => $matched
                        ? 'Condition matched. Followed the true branch.'
                        : 'Condition did not match. Followed the false branch.',
                ];
                $order = $target;

                continue;
            }

            if ($step->step_type === StepType::Notification) {
                $trace[] = ['order' => $step->step_order, 'name' => $step->name, 'kind' => 'notification', 'detail' => 'Notification step would run automatically and continue.'];
                $order = $ordered->has($order + 1) ? $order + 1 : null;

                continue;
            }

            if ($step->step_type === StepType::Auto) {
                $trace[] = ['order' => $step->step_order, 'name' => $step->name, 'kind' => 'automatic', 'detail' => 'Automatic step would record a system approval and continue.'];
                $order = $ordered->has($order + 1) ? $order + 1 : null;

                continue;
            }

            $trace[] = [
                'order' => $step->step_order,
                'name' => $step->name,
                'kind' => 'approval',
                'detail' => 'Simulation assumes this approval is granted so the rest of the route can be shown.',
            ];
            $order = $ordered->has($order + 1) ? $order + 1 : null;
        }

        $trace[] = ['order' => 999, 'name' => 'End', 'kind' => 'end', 'detail' => 'Simulation reached the end of the chain.'];

        return $trace;
    }

    /**
     * @param  array{title?: string, priority?: string, type?: string, metadata?: array<string, mixed>}  $sample
     */
    private function sampleSubmission(array $sample): Submission
    {
        $priority = SubmissionPriority::tryFrom((string) ($sample['priority'] ?? 'normal')) ?? SubmissionPriority::Normal;
        $type = SubmissionType::tryFrom((string) ($sample['type'] ?? 'request')) ?? SubmissionType::Request;

        $submission = new Submission([
            'title' => $sample['title'] ?? 'Sample request',
            'type' => $type,
            'priority' => $priority,
            'metadata' => $sample['metadata'] ?? [],
        ]);
        $submission->department_id = isset($sample['department_id']) ? (int) $sample['department_id'] : null;
        $submission->submitter_id = isset($sample['submitter_id']) ? (int) $sample['submitter_id'] : null;

        return $submission;
    }
}
