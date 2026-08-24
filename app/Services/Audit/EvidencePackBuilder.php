<?php

namespace App\Services\Audit;

use App\Models\Comment;
use App\Models\Delegation;
use App\Models\NotificationDelivery;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Support\Facades\Storage;

final class EvidencePackBuilder
{
    /**
     * @return array<string, string> filename => contents
     */
    public function files(Submission $submission): array
    {
        $submission->loadMissing([
            'submitter:id,name,email',
            'department:id,name',
            'requestTypeVersion.requestType',
            'fieldValues.field',
            'files',
            'comments.author:id,name',
            'workflowInstance.template',
            'workflowInstance.templateVersion',
            'workflowInstance.currentStep',
            'workflowInstance.actions.actor:id,name',
            'workflowInstance.actions.step',
        ]);

        $request = [
            'id' => $submission->id,
            'reference_number' => $submission->reference_number,
            'title' => $submission->title,
            'body' => $submission->body,
            'status' => $submission->status->value,
            'type' => $submission->type->value,
            'priority' => $submission->priority->value,
            'due_date' => $submission->due_date?->toDateString(),
            'submitter' => [
                'id' => $submission->submitter?->id,
                'name' => $submission->submitter?->name,
                'email' => $submission->submitter?->email,
            ],
            'department' => $submission->department?->name,
            'request_type' => $submission->requestTypeVersion?->requestType?->name,
            'request_type_version_id' => $submission->request_type_version_id,
            'fields' => $submission->fieldValues->map(fn ($value): array => [
                'label' => $value->field?->label,
                'value' => $value->field ? $value->resolvedValue() : null,
            ])->all(),
            'created_at' => $submission->created_at?->toIso8601String(),
            'updated_at' => $submission->updated_at?->toIso8601String(),
        ];

        $instance = $submission->workflowInstance;
        $workflow = [
            'instance_id' => $instance?->id,
            'status' => $instance?->status->value,
            'template' => $instance?->template?->name,
            'template_version_id' => $instance?->template_version_id,
            'current_step' => $instance?->currentStep?->name,
            'assignments' => $instance?->currentStep
                ? $instance->currentStep->assignedUsers($submission)->map(fn ($user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                ])->all()
                : [],
            'actions' => $instance?->actions->map(fn ($action): array => [
                'id' => $action->id,
                'action' => $action->action->value,
                'actor' => $action->actor?->name,
                'step' => $action->step?->name,
                'comment' => $action->comment,
                'created_at' => $action->created_at?->toIso8601String(),
            ])->all() ?? [],
        ];

        $documents = $submission->files->map(function (SubmissionFile $file): array {
            $contents = $this->fileContents($file);

            return [
                'id' => $file->id,
                'name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'sha256' => $contents === null ? null : hash('sha256', $contents),
            ];
        })->all();

        $comments = $submission->comments->map(fn (Comment $comment): array => [
            'id' => $comment->id,
            'author' => $comment->author?->name,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
        ])->all();

        $delegations = $instance === null
            ? []
            : Delegation::query()
                ->with(['delegator:id,name', 'delegate:id,name', 'step'])
                ->where('instance_id', $instance->id)
                ->get()
                ->map(fn (Delegation $delegation): array => [
                    'id' => $delegation->id,
                    'delegator' => $delegation->delegator?->name,
                    'delegate' => $delegation->delegate?->name,
                    'step' => $delegation->step?->name,
                    'starts_at' => $delegation->starts_at?->toIso8601String(),
                    'expires_at' => $delegation->expires_at?->toIso8601String(),
                ])->all();

        $notifications = NotificationDelivery::query()
            ->with('user:id,name,email')
            ->get()
            ->filter(fn (NotificationDelivery $delivery): bool => (int) ($delivery->payload['submission_id'] ?? 0) === $submission->id)
            ->values()
            ->map(fn (NotificationDelivery $delivery): array => [
                'id' => $delivery->id,
                'user' => $delivery->user?->name,
                'kind' => $delivery->kind,
                'channel' => $delivery->channel,
                'status' => $delivery->status->value,
                'created_at' => $delivery->created_at?->toIso8601String(),
            ])->all();

        $timestamps = [
            'generated_at' => now()->toIso8601String(),
            'request_created_at' => $submission->created_at?->toIso8601String(),
            'workflow_started_at' => $instance?->started_at?->toIso8601String(),
            'workflow_completed_at' => $instance?->completed_at?->toIso8601String(),
        ];

        return [
            'request.json' => $this->encode($request),
            'workflow.json' => $this->encode($workflow),
            'documents.json' => $this->encode($documents),
            'comments.json' => $this->encode($comments),
            'delegations.json' => $this->encode($delegations),
            'notifications.json' => $this->encode($notifications),
            'timestamps.json' => $this->encode($timestamps),
        ];
    }

    private function fileContents(SubmissionFile $file): ?string
    {
        if (! Storage::disk($file->disk)->exists($file->stored_path)) {
            return null;
        }

        return (string) Storage::disk($file->disk)->get($file->stored_path);
    }

    private function encode(mixed $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
