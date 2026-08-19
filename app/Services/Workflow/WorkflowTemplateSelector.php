<?php

namespace App\Services\Workflow;

use App\Models\RequestType;
use App\Models\Submission;
use App\Models\WorkflowSelectionRule;
use App\Models\WorkflowTemplate;
use App\Services\SubmissionConditionEvaluator;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class WorkflowTemplateSelector
{
    public function __construct(private readonly SubmissionConditionEvaluator $conditions) {}

    /**
     * @return array{template: WorkflowTemplate, explanation: string, rule: WorkflowSelectionRule|null}
     */
    public function select(Submission $submission): array
    {
        $submission->loadMissing('requestTypeVersion.requestType.workflowTemplate', 'fieldValues.field');
        $type = $submission->requestTypeVersion->requestType;

        $matched = $this->firstMatchingRule($type, $submission);
        if ($matched !== null) {
            $template = $matched->template;
            if ($template->is_active) {
                return [
                    'template' => $template->loadMissing('publishedVersion.steps'),
                    'explanation' => 'Matched selection rule "'.$matched->name.'" (priority '.$matched->priority.').',
                    'rule' => $matched,
                ];
            }
        }

        $assigned = $type->workflowTemplate;
        if ($assigned !== null && $assigned->is_active) {
            return [
                'template' => $assigned->loadMissing('publishedVersion.steps'),
                'explanation' => 'Used the workflow assigned on request type "'.$type->name.'".',
                'rule' => null,
            ];
        }

        $default = WorkflowTemplate::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        if ($default === null) {
            throw new InvalidArgumentException('No active default workflow template is available.');
        }

        return [
            'template' => $default->loadMissing('publishedVersion.steps'),
            'explanation' => 'Used the default fallback template because no matching rule or active assignment applied.',
            'rule' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return list<array{priority: int, name: string, matched: bool, template: string, detail: string}>
     */
    public function explain(RequestType $type, array $sample = []): array
    {
        $submission = $this->sampleSubmission($type, $sample);
        $trace = [];

        foreach ($this->activeRules($type) as $rule) {
            $matched = $this->ruleMatches($rule, $submission);
            $trace[] = [
                'priority' => $rule->priority,
                'name' => $rule->name,
                'matched' => $matched,
                'template' => $rule->template->name,
                'detail' => $matched
                    ? 'This rule matches the sample and would win if it is the first match.'
                    : 'This rule does not match the sample.',
            ];
        }

        $assigned = $type->workflowTemplate;
        $trace[] = [
            'priority' => 1000,
            'name' => 'Request type assignment',
            'matched' => $assigned !== null && $assigned->is_active,
            'template' => $assigned?->name ?? 'None',
            'detail' => $assigned !== null && $assigned->is_active
                ? 'Used when no earlier rule matches.'
                : 'No active assigned template; the default fallback is next.',
        ];

        $default = WorkflowTemplate::query()->where('is_active', true)->where('is_default', true)->first();
        $trace[] = [
            'priority' => 1001,
            'name' => 'Default template',
            'matched' => $default !== null,
            'template' => $default?->name ?? 'None',
            'detail' => 'Last resort for General Request and unassigned or inactive assignments.',
        ];

        return $trace;
    }

    /**
     * @return Collection<int, WorkflowSelectionRule>
     */
    private function activeRules(RequestType $type)
    {
        return WorkflowSelectionRule::query()
            ->with('template')
            ->where('request_type_id', $type->id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    private function firstMatchingRule(RequestType $type, Submission $submission): ?WorkflowSelectionRule
    {
        foreach ($this->activeRules($type) as $rule) {
            if ($this->ruleMatches($rule, $submission) && $rule->template->is_active) {
                return $rule;
            }
        }

        return null;
    }

    private function ruleMatches(WorkflowSelectionRule $rule, Submission $submission): bool
    {
        try {
            return $this->conditions->evaluate($submission, $rule->conditions ?? []);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $sample
     */
    private function sampleSubmission(RequestType $type, array $sample): Submission
    {
        $submission = new Submission([
            'title' => $sample['title'] ?? 'Sample request',
            'priority' => $sample['priority'] ?? 'normal',
            'type' => $sample['type'] ?? 'request',
            'metadata' => $sample['metadata'] ?? [],
        ]);
        $submission->setRelation('requestTypeVersion', $type->publishedVersion ?? $type->versions()->first());
        $submission->department_id = isset($sample['department_id']) ? (int) $sample['department_id'] : null;
        $submission->submitter_id = isset($sample['submitter_id']) ? (int) $sample['submitter_id'] : null;
        $submission->setRelation('fieldValues', collect());

        foreach ($sample['fields'] ?? [] as $key => $value) {
            $submission->metadata = array_merge($submission->metadata ?? [], ['fields' => array_merge(
                data_get($submission->metadata, 'fields', []),
                [$key => $value],
            )]);
        }

        return $submission;
    }
}
