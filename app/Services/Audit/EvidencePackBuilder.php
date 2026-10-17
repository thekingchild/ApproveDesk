<?php

namespace App\Services\Audit;

use App\Models\Comment;
use App\Models\Delegation;
use App\Models\NotificationDelivery;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionFileRevision;
use App\Models\SubmissionRevision;
use App\Models\User;
use App\Services\Privacy\ExportRedactor;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;

final class EvidencePackBuilder
{
    /**
     * @return array<string, string> filename => contents
     */
    public function files(Submission $submission, ?User $actor = null): array
    {
        $submission->loadMissing([
            'submitter:id,name,email',
            'department:id,name',
            'requestTypeVersion.requestType',
            'fieldValues.field',
            'files' => fn ($files) => $files->withTrashed()->with('remover:id,name'),
            'comments.author:id,name',
            'comments.revisions.editor:id,name',
            'revisions.actor:id,name',
            'fileRevisions.actor:id,name',
            'workflowInstance.template',
            'workflowInstance.templateVersion',
            'workflowInstance.currentStep',
            'workflowInstance.actions.actor:id,name',
            'workflowInstance.actions.onBehalfOf:id,name',
            'workflowInstance.actions.delegate:id,name',
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
            'fields' => $submission->fieldValues->map(function ($value) use ($submission, $actor): array {
                $raw = $value->field ? $value->resolvedValue() : null;
                $redacted = $actor !== null && $value->field !== null
                    ? app(ExportRedactor::class)->value($actor, $value->field, $submission, $raw, 'evidence')
                    : $raw;

                return [
                    'label' => $value->field?->label,
                    'value' => $redacted,
                    'redacted' => $value->field?->is_sensitive && $redacted === ExportRedactor::PLACEHOLDER,
                ];
            })->all(),
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
                'on_behalf_of' => $action->onBehalfOf?->name,
                'delegated_to' => $action->delegate?->name,
                'step' => $action->step?->name,
                'comment' => $action->comment,
                'created_at' => $action->created_at?->toIso8601String(),
            ])->all() ?? [],
        ];

        $documents = $submission->files->map(function (SubmissionFile $file): array {
            return [
                'id' => $file->id,
                'name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'sha256' => $this->fileHash($file),
                'removed_at' => $file->deleted_at?->toIso8601String(),
                'removed_by' => $file->remover?->name,
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
            ->where('submission_id', $submission->id)
            ->orderBy('id')
            ->get()
            ->map(fn (NotificationDelivery $delivery): array => [
                'id' => $delivery->id,
                'user' => $delivery->user?->name,
                'kind' => $delivery->kind,
                'channel' => $delivery->channel,
                'status' => $delivery->status->value,
                'sent_at' => $delivery->sent_at?->toIso8601String(),
                'created_at' => $delivery->created_at?->toIso8601String(),
            ])->all();

        // Histories kept by the app but missing from packs before RV-DATA-07.
        // Oldest first, so the files read as a timeline.
        $requestRevisions = $submission->revisions->sortBy('id')->values()->map(fn (SubmissionRevision $revision): array => [
            'id' => $revision->id,
            'actor' => $revision->actor?->name,
            'summary' => $revision->summary,
            'before' => $revision->before,
            'after' => $revision->after,
            'created_at' => $revision->created_at?->toIso8601String(),
        ])->all();

        $commentRevisions = [];
        foreach ($submission->comments as $comment) {
            foreach ($comment->revisions as $revision) {
                $commentRevisions[] = [
                    'id' => $revision->id,
                    'comment_id' => $comment->id,
                    'editor' => $revision->editor?->name,
                    'previous_body' => $revision->body,
                    'replaced_at' => $revision->created_at?->toIso8601String(),
                ];
            }
        }
        usort($commentRevisions, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        $fileHistory = $submission->fileRevisions->sortBy('id')->values()->map(fn (SubmissionFileRevision $revision): array => [
            'id' => $revision->id,
            'file_id' => $revision->submission_file_id,
            'action' => $revision->action->value,
            'name' => $revision->original_name,
            'sha256' => $revision->sha256,
            'size_bytes' => $revision->size_bytes,
            'actor' => $revision->actor?->name,
            'created_at' => $revision->created_at?->toIso8601String(),
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
            'request_revisions.json' => $this->encode($requestRevisions),
            'comment_revisions.json' => $this->encode($commentRevisions),
            'file_history.json' => $this->encode($fileHistory),
            'in_app_notifications.json' => $this->encode($this->inAppNotifications($submission)),
        ];
    }

    /**
     * In-app (database channel) notifications about this request. Laravel keeps
     * them in `notifications` with the request id inside the JSON payload, not in
     * notification_deliveries (RV-DATA-07). Emails and chat messages are in
     * notifications.json (RV-DATA-09).
     *
     * @return list<array<string, mixed>>
     */
    private function inAppNotifications(Submission $submission): array
    {
        $rows = DatabaseNotification::query()
            ->where('data->submission_id', $submission->id)
            ->orderBy('created_at')
            ->get(['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at']);

        $names = User::query()
            ->whereIn('id', $rows->where('notifiable_type', (new User)->getMorphClass())->pluck('notifiable_id')->unique()->all())
            ->pluck('name', 'id');

        $list = [];
        foreach ($rows as $notification) {
            $list[] = [
                'id' => $notification->id,
                'user' => $names[$notification->notifiable_id] ?? null,
                'kind' => $notification->data['kind'] ?? class_basename($notification->type),
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ];
        }

        return $list;
    }

    /** Streams the file through the hash so large attachments are not held in memory (RV-PERF-12). */
    private function fileHash(SubmissionFile $file): ?string
    {
        $disk = Storage::disk($file->disk);
        if (! $disk->exists($file->stored_path)) {
            return null;
        }

        $stream = $disk->readStream($file->stored_path);
        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    private function encode(mixed $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
