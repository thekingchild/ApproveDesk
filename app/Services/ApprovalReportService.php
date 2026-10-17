<?php

namespace App\Services;

use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplateStep;
use App\Models\WorkflowTemplateVersion;
use App\Services\Workflow\StepOutcomes;
use App\Support\ApprovalStatusStamp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class ApprovalReportService
{
    /** Recorded outcomes that settle a step, and the state the report shows for each. */
    private const STEP_OUTCOME_STATES = [
        'approve' => 'Completed',
        'approve_with_conditions' => 'Completed',
        'auto_approve' => 'Completed',
        'condition_matched' => 'Completed',
        'condition_not_matched' => 'Skipped',
        'escalate' => 'Escalated',
        'reject' => 'Rejected',
        'auto_reject' => 'Rejected',
        'return' => 'Returned',
        'withdraw' => 'Withdrawn',
    ];

    public function __construct(
        private readonly ApprovalStatusStamp $statusStamp,
    ) {}

    /** @return array<string, mixed> */
    public function build(Submission $submission, User $viewer, ?Carbon $generatedAt = null): array
    {
        $submission->loadMissing([
            'submitter',
            'department',
            'files.uploader',
            'requestTypeVersion.requestType',
            'requestTypeVersion.fields',
            'fieldValues.field',
            'fieldValues.userValue',
            'fieldValues.departmentValue',
            'workflowInstance.template.steps',
            'workflowInstance.templateVersion.steps',
            'workflowInstance.currentStep',
            'workflowInstance.actions.actor',
            'workflowInstance.actions.onBehalfOf:id,name',
            'workflowInstance.actions.step',
        ]);

        $requestType = $submission->requestTypeVersion->requestType;
        $fieldAccess = app(RequestFieldAccess::class);
        $fields = $requestType->is_system
            ? []
            : $submission->requestTypeVersion->fields
                ->sortBy('position')
                ->filter(fn ($field): bool => $fieldAccess->canView($viewer, $field, $submission))
                ->map(fn ($field): array => [
                    'label' => $field->label,
                    'value' => $fieldAccess->reveal($viewer, $field, $submission, 'approval_report'),
                ])
                ->values()
                ->all();

        $instance = $submission->workflowInstance;
        $actionsByStep = $instance === null ? collect() : $instance->actions->groupBy('step_id');
        $version = $instance?->templateVersion;
        $stepSource = $version !== null
            ? $version->steps
            : ($instance?->template->steps ?? collect());
        $steps = $stepSource
            ->sortBy('step_order')
            ->map(function ($step) use ($instance, $actionsByStep, $submission): array {
                $stepActions = $actionsByStep->get($step->id, collect())
                    ->sortBy(fn ($action): string => sprintf('%020d-%020d', $action->created_at?->getTimestamp() ?? 0, $action->id))
                    ->values();
                $isCurrent = $instance !== null
                    && $instance->current_step_id === $step->id
                    && in_array($instance->status, [WorkflowStatus::Active, WorkflowStatus::OnHold], true);
                $decided = $stepActions->filter(fn ($action): bool => array_key_exists($action->action->value, self::STEP_OUTCOME_STATES));
                $finished = $instance !== null && ! in_array($instance->status, [WorkflowStatus::Active, WorkflowStatus::OnHold], true);

                // The step's state comes from its last recorded outcome, so a
                // withdrawn step is not reported as completed (RV-BUG-22).
                $state = match (true) {
                    $isCurrent => 'Current',
                    $decided->isNotEmpty() => self::STEP_OUTCOME_STATES[$decided->last()->action->value],
                    $finished => 'Not reached',
                    default => 'Pending',
                };

                return [
                    'name' => $step->name,
                    'state' => $state,
                    'actors' => $this->stepPeople($step, $submission, $stepActions, $isCurrent || $state === 'Pending'),
                ];
            })
            ->values()
            ->all();

        $actions = $instance === null
            ? []
            : $instance->actions
                ->sortBy(fn ($action): string => sprintf('%020d-%020d', $action->created_at?->getTimestamp() ?? 0, $action->id))
                ->map(fn ($action): array => [
                    'step' => $action->step->name,
                    'actor' => $action->actor_id === null
                        ? 'System'
                        : $action->actor->name.($action->on_behalf_of_id !== null ? ' (on behalf of '.$action->onBehalfOf->name.')' : ''),
                    'outcome' => $this->outcomeLabel($action, $instance->templateVersion),
                    'acted_at' => $action->created_at?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i') ?? '—',
                    'comment' => $action->comment,
                ])
                ->values()
                ->all();

        return [
            'submission' => $submission,
            'body' => app(RichTextSanitizer::class)->sanitize((string) $submission->body),
            'logo_data_uri' => $this->assetDataUri(Setting::get('brand.logo')),
            'favicon_data_uri' => $this->assetDataUri(Setting::get('brand.favicon')),
            'status_stamp_key' => $this->statusStamp->key($submission->status),
            'status_stamp_data_uri' => $this->statusStamp->dataUri($submission->status),
            'request_type' => $requestType->name,
            'fields' => $fields,
            'workflow_steps' => $steps,
            'workflow_actions' => $actions,
            'attachments' => $submission->files->map(fn ($file): array => [
                'name' => $file->original_name,
                'type' => $file->mime_type,
                'size' => $this->fileSize((int) $file->size_bytes),
                'uploaded_by' => $file->uploader->name,
            ])->all(),
            'generated_at' => ($generatedAt ?? now())->timezone((string) config('app.timezone')),
            'timezone' => (string) config('app.timezone'),
        ];
    }

    /**
     * Steps still waiting show who is assigned now; steps already settled show
     * the people who actually decided them, as recorded, so later changes to
     * assignments or deactivated accounts do not rewrite history (RV-BUG-22).
     *
     * @param  Collection<int, WorkflowAction>  $stepActions
     */
    private function stepPeople(WorkflowTemplateStep $step, Submission $submission, Collection $stepActions, bool $waiting): string
    {
        if ($waiting) {
            return $step->step_type->isAutomatic()
                ? 'System'
                : $step->assignedUsers($submission)->pluck('name')->implode(', ');
        }

        // Withdrawing is the requester's act, not a decision on the step.
        $decisions = $stepActions->filter(fn (WorkflowAction $action): bool => $action->action !== WorkflowActionType::Withdraw
            && array_key_exists($action->action->value, self::STEP_OUTCOME_STATES));

        return $decisions
            ->map(fn (WorkflowAction $action): string => $action->actor_id === null
                ? 'System'
                : $action->actor->name.($action->on_behalf_of_id !== null ? ' (on behalf of '.$action->onBehalfOf->name.')' : ''))
            ->unique()
            ->implode(', ');
    }

    private function assetDataUri(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $file = basename($path);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        $disk = Storage::disk('public');
        $stored = 'branding/'.$file;

        if (! $disk->exists($stored)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($stored));
    }

    private function outcomeLabel(WorkflowAction $action, ?WorkflowTemplateVersion $version): string
    {
        $key = $action->outcome_key ?: $action->action->value;
        $definition = app(StepOutcomes::class)->find($version, $key);

        return $definition['label'] ?? str($key)->replace('_', ' ')->ucfirst()->toString();
    }

    private function fileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
