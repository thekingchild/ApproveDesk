<?php

namespace App\Services;

use App\Enums\WorkflowActionType;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplateVersion;
use App\Services\Workflow\StepOutcomes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

final class ApprovalReportService
{
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
                $stepActions = $actionsByStep->get($step->id, collect());
                $state = match (true) {
                    $stepActions->contains(fn ($action): bool => in_array($action->action, [WorkflowActionType::Reject, WorkflowActionType::AutoReject], true)) => 'Rejected',
                    $instance !== null && $instance->current_step_id === $step->id && in_array($instance->status->value, ['active', 'on_hold'], true) => 'Current',
                    $stepActions->isNotEmpty() => 'Completed',
                    default => 'Pending',
                };

                return [
                    'name' => $step->name,
                    'state' => $state,
                    'actors' => $step->step_type->isAutomatic()
                        ? 'System'
                        : $step->assignedUsers($submission)->pluck('name')->implode(', '),
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
                    'actor' => $action->actor_id === null ? 'System' : $action->actor->name,
                    'outcome' => $this->outcomeLabel($action, $instance->templateVersion),
                    'acted_at' => $action->created_at?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i') ?? '—',
                    'comment' => $action->comment,
                ])
                ->values()
                ->all();

        return [
            'submission' => $submission,
            'body' => app(RichTextSanitizer::class)->sanitize((string) $submission->body),
            'logo_data_uri' => $this->logoDataUri(),
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

    private function logoDataUri(): ?string
    {
        $storedPath = Setting::get('brand.logo');

        if ($storedPath === null || $storedPath === '') {
            return null;
        }

        $file = basename($storedPath);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        $disk = Storage::disk('public');
        $path = 'branding/'.$file;

        if (! $disk->exists($path)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
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
