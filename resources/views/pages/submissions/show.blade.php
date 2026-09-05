<?php

use App\Actions\Collaboration\EditCommentAction;
use App\Actions\Collaboration\FollowSubmissionAction;
use App\Actions\Collaboration\NudgeApproversAction;
use App\Actions\Collaboration\PostCommentAction;
use App\Actions\Collaboration\UnfollowSubmissionAction;
use App\Actions\Collaboration\UpdateWatcherPreferencesAction;
use App\Enums\WorkflowActionType;
use App\Exceptions\CollaborationException;
use App\Exceptions\WorkflowException;
use App\Models\Comment;
use App\Models\Delegation;
use App\Models\Submission;
use App\Models\User;
use App\Services\ApproverWorkbench;
use App\Services\Collaboration\ExpectedProgress;
use App\Services\Collaboration\MentionParser;
use App\Services\Workflow\AssigneeDirectory;
use App\Services\WorkflowEngine;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Submission')] class extends Component {
    public Submission $submission;

    public string $comment = '';

    public string $actionComment = '';

    public string $delegateUserId = '';

    public string $delegationStartsAt = '';

    public string $delegationExpiresAt = '';

    public string $delegationComment = '';

    public string $watcherQuery = '';

    public string $mentionQuery = '';

    public string $nudgeMessage = '';

    public string $replyBody = '';

    public ?int $replyParentId = null;

    public ?int $editingCommentId = null;

    public string $editBody = '';

    public bool $notifyComments = true;

    public bool $notifyStatus = true;

    /** @var array<int, int> */
    public array $mentionedUserIds = [];

    public string $resubmissionComment = '';

    public ?int $previewFileId = null;

    public function mount(Submission $submission): void
    {
        $this->authorize('view', $submission);
        $this->submission = $submission->load([
            'submitter', 'department', 'files.uploader',
            'requestTypeVersion.requestType', 'requestTypeVersion.fields',
            'fieldValues.field', 'fieldValues.userValue', 'fieldValues.departmentValue',
            'workflowInstance.template.steps',
            'workflowInstance.templateVersion',
            'workflowInstance.currentStep',
            'workflowInstance.actions.actor',
            'watchers.user',
            'revisions.actor:id,name',
            'fileRevisions',
            'submitter.manager',
            'submitter.department.manager',
            'department.manager',
        ]);

        $mine = $this->submission->watchers->firstWhere('user_id', Auth::id());
        if ($mine) {
            $this->notifyComments = $mine->notify_comments;
            $this->notifyStatus = $mine->notify_status;
        }
    }

    #[Computed]
    public function visibleCustomFields(): \Illuminate\Support\Collection
    {
        return $this->submission->requestTypeVersion->fields
            ->filter(fn ($field) => app(\App\Services\RequestFieldAccess::class)->canView(Auth::user(), $field, $this->submission))
            ->each(function ($field): void {
                if ($field->is_sensitive) {
                    app(\App\Services\RequestFieldAccess::class)->reveal(Auth::user(), $field, $this->submission, 'view');
                }
            });
    }

    /** Per-step timeline state: pending, active, done, rejected. */
    #[Computed]
    public function timeline(): array
    {
        $instance = $this->submission->workflowInstance;

        if ($instance === null) {
            return [];
        }

        $actions = $instance->actions->groupBy('step_id');

        return $instance->template->steps->map(function ($step) use ($instance, $actions) {
            $stepActions = $actions->get($step->id, collect());
            $assignedUsers = $step->assignedUsers($instance->submission);
            $automatic = $step->step_type->isAutomatic();
            $approvalCount = $stepActions->whereIn('action', [WorkflowActionType::Approve, WorkflowActionType::ApproveWithConditions])->unique('actor_id')->count();
            $requiredApprovals = match ($step->groupCompletion()) {
                \App\Enums\GroupCompletion::FirstResponse => 1,
                \App\Enums\GroupCompletion::All => $assignedUsers->count(),
                \App\Enums\GroupCompletion::Quorum => $step->quorum_count,
                default => null,
            };

            $state = match (true) {
                $stepActions->contains('action', WorkflowActionType::Reject) => 'rejected',
                $instance->current_step_id === $step->id && in_array($instance->status->value, ['active', 'on_hold'], true) => 'active',
                $stepActions->contains(fn ($action) => in_array($action->action, [
                    WorkflowActionType::Approve,
                    WorkflowActionType::Escalate,
                    WorkflowActionType::AutoApprove,
                    WorkflowActionType::ConditionMatched,
                    WorkflowActionType::ConditionNotMatched,
                ], true)) => 'done',
                default => 'pending',
            };

            return [
                'name' => $step->name,
                'order' => $step->step_order,
                'state' => $state,
                'actors' => $automatic ? __('System') : $assignedUsers->pluck('name')->implode(', '),
                'progress' => $requiredApprovals === null ? null : "{$approvalCount} / {$requiredApprovals} approvals",
                'acted_at' => $stepActions->first()?->created_at?->format('d M Y H:i'),
            ];
        })->all();
    }

    #[Computed]
    public function canAct(): bool
    {
        $instance = $this->submission->workflowInstance;

        if ($instance === null || $instance->status->value !== 'active') {
            return false;
        }

        $user = Auth::user();
        $step = $instance->currentStep;

        if ($step === null) {
            return false;
        }

        $direct = $step->assignedUsers()->contains($user);
        $incoming = Delegation::query()->active()
            ->where('instance_id', $instance->id)
            ->where('step_id', $step->id)
            ->where('delegate_id', $user->id)
            ->exists();
        $outgoing = $direct && Delegation::query()->active()
            ->where('instance_id', $instance->id)
            ->where('step_id', $step->id)
            ->where('delegator_id', $user->id)
            ->exists();

        if ((! $direct && ! $incoming) || $outgoing) {
            return false;
        }

        return ! $instance->actions
            ->where('step_id', $step->id)
            ->where('actor_id', $user->id)
            ->whereIn('action', ['approve', 'reject', 'escalate'])
            ->isNotEmpty();
    }

    /** @return array{previous: string|null, next: string|null} */
    #[Computed]
    public function approvalNavigation(): array
    {
        $items = collect(app(ApproverWorkbench::class)->itemsFor(Auth::user()))
            ->filter(fn (array $item): bool => $item['needs_me'])
            ->values();
        $index = $items->search(fn (array $item): bool => $item['instance']->submission_id === $this->submission->id);

        if ($index === false) {
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $index > 0 ? route('submissions.show', $items[$index - 1]['instance']->submission) : null,
            'next' => $index < $items->count() - 1 ? route('submissions.show', $items[$index + 1]['instance']->submission) : null,
        ];
    }

    #[Computed]
    public function canDelegate(): bool
    {
        $instance = $this->submission->workflowInstance;
        $step = $instance?->currentStep;
        $user = Auth::user();

        return $instance?->status->value === 'active'
            && $step?->allow_delegation
            && $step->assignedUsers()->contains($user)
            && ! Delegation::query()->active()
                ->where('instance_id', $instance->id)
                ->where('step_id', $step->id)
                ->where('delegator_id', $user->id)
                ->exists()
            && ! $instance->actions
                ->where('step_id', $step->id)
                ->where('actor_id', $user->id)
                ->whereIn('action', ['approve', 'reject', 'escalate'])
                ->isNotEmpty();
    }

    #[Computed]
    public function delegateCandidates(): \Illuminate\Database\Eloquent\Collection
    {
        $step = $this->submission->workflowInstance?->currentStep;
        $excluded = $step?->assignedUsers()->pluck('id')->all() ?? [];

        return User::query()
            ->assignable()
            ->where('is_active', true)
            ->whereKeyNot(Auth::id())
            ->whereNotIn('id', $excluded)
            ->orderBy('name')
            ->get();
    }

    public function delegateApproval(): void
    {
        $validated = $this->validate([
            'delegateUserId' => ['required', 'integer', Rule::exists(User::class, 'id')->where('is_active', true)],
            'delegationStartsAt' => ['nullable', 'date'],
            'delegationExpiresAt' => ['required', 'date', 'after:now'],
            'delegationComment' => ['nullable', 'string', 'max:1000'],
        ]);

        $startsAt = filled($validated['delegationStartsAt'] ?? null)
            ? \Illuminate\Support\Carbon::parse($validated['delegationStartsAt'])
            : now();

        if ($startsAt->greaterThanOrEqualTo(\Illuminate\Support\Carbon::parse($validated['delegationExpiresAt']))) {
            $this->addError('delegationStartsAt', __('The start must be before the expiry.'));

            return;
        }

        $delegate = User::query()
            ->assignable()
            ->where('is_active', true)
            ->find((int) $validated['delegateUserId']);
        if (! $delegate instanceof User) {
            $this->addError('delegateUserId', __('Choose an available delegate.'));

            return;
        }

        try {
            app(WorkflowEngine::class)->delegate(
                $this->submission->workflowInstance,
                Auth::user(),
                $delegate,
                \Illuminate\Support\Carbon::parse($validated['delegationExpiresAt']),
                $validated['delegationComment'] ?: null,
                $startsAt,
            );
        } catch (WorkflowException $exception) {
            $this->addError('delegateUserId', $exception->getMessage());

            return;
        }

        Flux::toast('Approval delegated.', variant: 'success');
        $this->redirect(route('submissions.show', $this->submission), navigate: true);
    }

    public function act(string $action): void
    {
        $this->resetValidation();

        $outcomes = app(\App\Services\Workflow\StepOutcomes::class)->forVersion($this->submission->workflowInstance?->templateVersion);
        $outcome = collect($outcomes)->firstWhere('key', $action);
        $requiresComment = $outcome['requires_comment'] ?? in_array($action, ['reject', 'return', 'request_information', 'escalate', 'approve_with_conditions'], true);

        if ($requiresComment && trim($this->actionComment) === '') {
            $this->addError('actionComment', 'A comment is required for this action.');

            return;
        }

        try {
            $actionType = WorkflowActionType::tryFrom($action) ?? WorkflowActionType::Approve;
            app(WorkflowEngine::class)->processAction(
                $this->submission->workflowInstance,
                Auth::user(),
                $actionType,
                trim($this->actionComment) !== '' ? trim($this->actionComment) : null,
                $outcome !== null ? $action : null,
            );
        } catch (WorkflowException $exception) {
            Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast('Recorded.', variant: 'success');
        $this->redirect(route('submissions.show', $this->submission), navigate: true);
    }

    public function postComment(): void
    {
        $this->validate(['comment' => ['required', 'string', 'max:5000']]);
        $this->authorize('comment', $this->submission);

        try {
            app(PostCommentAction::class)->execute(
                $this->submission,
                Auth::user(),
                $this->comment,
                null,
                $this->mentionedUserIds,
            );
        } catch (CollaborationException $exception) {
            $this->addError('comment', $exception->getMessage());

            return;
        }

        $this->reset('comment', 'mentionedUserIds', 'mentionQuery');
        $this->refreshCollaboration();
    }

    public function postReply(): void
    {
        $this->validate([
            'replyBody' => ['required', 'string', 'max:5000'],
            'replyParentId' => ['required', 'integer'],
        ]);
        $this->authorize('comment', $this->submission);

        try {
            app(PostCommentAction::class)->execute(
                $this->submission,
                Auth::user(),
                $this->replyBody,
                $this->replyParentId,
            );
        } catch (CollaborationException $exception) {
            $this->addError('replyBody', $exception->getMessage());

            return;
        }

        $this->reset('replyBody', 'replyParentId');
        $this->refreshCollaboration();
    }

    public function startReply(int $commentId): void
    {
        $this->replyParentId = $commentId;
        $this->replyBody = '';
        $this->editingCommentId = null;
    }

    public function startEdit(int $commentId): void
    {
        $comment = Comment::query()->findOrFail($commentId);
        $this->authorize('update', $comment);
        $this->editingCommentId = $comment->id;
        $this->editBody = $comment->body;
        $this->replyParentId = null;
    }

    public function saveEdit(): void
    {
        $this->validate(['editBody' => ['required', 'string', 'max:5000']]);
        $comment = Comment::query()->findOrFail($this->editingCommentId);

        try {
            app(EditCommentAction::class)->execute($comment, Auth::user(), $this->editBody);
        } catch (CollaborationException $exception) {
            $this->addError('editBody', $exception->getMessage());

            return;
        }

        $this->reset('editingCommentId', 'editBody');
        $this->refreshCollaboration();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingCommentId', 'editBody', 'replyParentId', 'replyBody');
    }

    public function follow(): void
    {
        try {
            app(FollowSubmissionAction::class)->execute($this->submission, Auth::user(), Auth::user(), [
                'notify_comments' => $this->notifyComments,
                'notify_status' => $this->notifyStatus,
            ]);
        } catch (CollaborationException $exception) {
            Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->refreshCollaboration();
        Flux::toast('You are following this request.', variant: 'success');
    }

    public function unfollow(): void
    {
        try {
            app(UnfollowSubmissionAction::class)->execute($this->submission, Auth::user(), Auth::user());
        } catch (CollaborationException $exception) {
            Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->refreshCollaboration();
        Flux::toast('You unfollowed this request.', variant: 'success');
    }

    public function addWatcher(int $userId): void
    {
        try {
            app(FollowSubmissionAction::class)->execute(
                $this->submission,
                Auth::user(),
                User::query()->findOrFail($userId),
            );
        } catch (CollaborationException $exception) {
            Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->watcherQuery = '';
        $this->refreshCollaboration();
        Flux::toast('Follower added.', variant: 'success');
    }

    public function removeWatcher(int $userId): void
    {
        try {
            app(UnfollowSubmissionAction::class)->execute(
                $this->submission,
                Auth::user(),
                User::query()->findOrFail($userId),
            );
        } catch (CollaborationException $exception) {
            Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->refreshCollaboration();
    }

    public function saveWatcherPreferences(): void
    {
        try {
            app(UpdateWatcherPreferencesAction::class)->execute(
                $this->submission,
                Auth::user(),
                $this->notifyComments,
                $this->notifyStatus,
            );
        } catch (CollaborationException $exception) {
            Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast('Follow preferences saved.', variant: 'success');
    }

    public function addMention(int $userId): void
    {
        $parser = app(MentionParser::class);
        $this->comment = $parser->appendToken($this->comment, $userId);
        $this->mentionedUserIds = array_values(array_unique([...$this->mentionedUserIds, $userId]));
        $this->mentionQuery = '';
        unset($this->mentionResults);
    }

    public function removeMention(int $userId): void
    {
        $this->mentionedUserIds = array_values(array_filter($this->mentionedUserIds, fn (int $id): bool => $id !== $userId));
        $this->comment = trim((string) preg_replace('/@\{'.$userId.'\}/', '', $this->comment));
        unset($this->mentionResults);
    }

    public function sendNudge(): void
    {
        try {
            app(NudgeApproversAction::class)->execute(
                $this->submission,
                Auth::user(),
                $this->nudgeMessage !== '' ? $this->nudgeMessage : null,
            );
        } catch (CollaborationException $exception) {
            $this->addError('nudgeMessage', $exception->getMessage());

            return;
        }

        $this->reset('nudgeMessage');
        Flux::toast('Reminder sent.', variant: 'success');
    }

    #[Computed]
    public function commentThreads(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->submission->comments()
            ->with(['author', 'mentions.user', 'revisions.editor', 'replies.author', 'replies.mentions.user', 'replies.revisions.editor'])
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function expectedProgress(): ?array
    {
        return app(ExpectedProgress::class)->for($this->submission);
    }

    #[Computed]
    public function watcherResults(): \Illuminate\Support\Collection
    {
        $exclude = $this->submission->watchers->pluck('user_id')->all();
        $exclude[] = $this->submission->submitter_id;

        return app(AssigneeDirectory::class)->search('user', $this->watcherQuery, $exclude);
    }

    #[Computed]
    public function mentionResults(): \Illuminate\Support\Collection
    {
        return app(AssigneeDirectory::class)->search('user', $this->mentionQuery, [Auth::id(), ...$this->mentionedUserIds]);
    }

    #[Computed]
    public function mentionedUsers(): \Illuminate\Support\Collection
    {
        return app(AssigneeDirectory::class)->selected('user', $this->mentionedUserIds);
    }

    public function displayComment(Comment $comment): string
    {
        return app(MentionParser::class)->display(
            $comment->body,
            $comment->mentions->map->user->filter(),
        );
    }

    private function refreshCollaboration(): void
    {
        $this->submission->load(['watchers.user', 'comments.author']);
        unset($this->commentThreads, $this->watcherResults, $this->mentionResults, $this->mentionedUsers);
        $mine = $this->submission->watchers->firstWhere('user_id', Auth::id());
        if ($mine) {
            $this->notifyComments = $mine->notify_comments;
            $this->notifyStatus = $mine->notify_status;
        }
    }

    public function withdraw(): void
    {
        $this->authorize('withdraw', $this->submission);

        app(\App\Actions\Submissions\WithdrawSubmissionAction::class)->execute($this->submission);

        Flux::toast('Submission withdrawn.', variant: 'success');

        $this->redirect(route('submissions.show', $this->submission), navigate: true);
    }

    public function resubmit(): void
    {
        $this->authorize('submit', $this->submission);

        if ($this->submission->status === \App\Enums\SubmissionStatus::AwaitingInformation) {
            $validated = $this->validate([
                'resubmissionComment' => ['required', 'string', 'max:5000'],
            ]);

            app(PostCommentAction::class)->execute(
                $this->submission,
                Auth::user(),
                $validated['resubmissionComment'],
            );
        }

        app(\App\Actions\Submissions\SubmitSubmissionAction::class)->execute($this->submission);

        Flux::toast('Resubmitted for approval.', variant: 'success');

        $this->redirect(route('submissions.show', $this->submission), navigate: true);
    }

    public function fileUrl(\App\Models\SubmissionFile $file): string
    {
        return URL::temporarySignedRoute(
            'submissions.files.download',
            now()->addHours(2),
            ['submission' => $this->submission->id, 'file' => $file->id],
        );
    }

    #[Computed]
    public function previewFile(): ?\App\Models\SubmissionFile
    {
        return $this->submission->files->firstWhere('id', $this->previewFileId);
    }

    public function openPreview(int $fileId): void
    {
        $file = $this->submission->files->firstWhere('id', $fileId);
        abort_unless($file?->isPreviewable(), 404);
        $this->authorize('downloadFiles', $this->submission);

        $this->previewFileId = $file->id;
        unset($this->previewFile);
        $this->modal('file-preview')->show();
    }

    public function previewUrl(\App\Models\SubmissionFile $file): string
    {
        return URL::temporarySignedRoute(
            'submissions.files.download',
            now()->addMinutes(30),
            ['submission' => $this->submission->id, 'file' => $file->id, 'preview' => 1],
        );
    }
} ?>

<div
    class="space-y-6"
    x-data="{ previous: @js($this->approvalNavigation['previous']), next: @js($this->approvalNavigation['next']) }"
    x-on:keydown.window="
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) || $event.metaKey || $event.ctrlKey || $event.altKey) return;
        if ($event.key === '[' && previous) window.location.assign(previous);
        if ($event.key === ']' && next) window.location.assign(next);
        if ($event.key.toLowerCase() === 'a') document.querySelector('[data-test=approve-modal-trigger]')?.click();
        if ($event.key.toLowerCase() === 'r') document.querySelector('[data-test=reject-modal-trigger]')?.click();
    "
>
    <x-ui.breadcrumbs :items="[['label' => __('Submissions'), 'url' => route('submissions.index')], ['label' => $submission->reference_number]]" :back="route('submissions.index')" back-label="Back to submissions" />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ $submission->title }}</flux:heading>
            <flux:subheading class="font-mono">{{ $submission->reference_number }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.help-link article="collaboration" />
            <flux:button :href="route('submissions.approval-report.show', $submission)" target="_blank" variant="ghost" icon="printer">{{ __('Approval report') }}</flux:button>
            <flux:button :href="route('submissions.approval-report.download', $submission)" variant="ghost" icon="arrow-down-tray">{{ __('Download PDF') }}</flux:button>
            @can('downloadEvidence', $submission)
                <flux:button :href="route('submissions.evidence.download', $submission)" variant="ghost" data-test="download-evidence">{{ __('Download evidence pack') }}</flux:button>
            @endcan
            @can('update', $submission)
                <flux:button :href="$submission->requestTypeVersion->requestType->is_system ? route('submissions.edit', $submission) : route('submissions.custom.edit', $submission)" variant="primary" wire:navigate>{{ $submission->status === \App\Enums\SubmissionStatus::Draft ? __('Edit draft') : __('Edit and respond') }}</flux:button>
            @endcan
            <flux:badge color="{{ ['draft' => 'zinc', 'in_review' => 'blue', 'approved' => 'lime', 'rejected' => 'red', 'returned' => 'amber', 'awaiting_information' => 'amber', 'withdrawn' => 'zinc', 'pending' => 'blue'][$submission->status->value] ?? 'zinc' }}">
                {{ str_replace('_', ' ', $submission->status->value) }}
            </flux:badge>
            <flux:badge color="{{ ['low' => 'zinc', 'normal' => 'blue', 'high' => 'amber', 'urgent' => 'red'][$submission->priority->value] ?? 'zinc' }}">
                {{ $submission->priority->value }}
            </flux:badge>
            @if ($submission->workflowInstance?->deadlineAt())
                <flux:badge :color="$submission->workflowInstance->isOverdue() ? 'red' : 'zinc'">
                    {{ $submission->workflowInstance->isOverdue()
                        ? __('Overdue')
                        : __('Due').' '.$submission->workflowInstance->deadlineAt()->diffForHumans() }}
                </flux:badge>
            @endif
            @can('nudge', $submission)
                @if ($submission->workflowInstance?->status->value === 'active')
                    <flux:modal.trigger name="nudge-modal">
                        <flux:button size="sm" variant="ghost" data-test="nudge-trigger">{{ __('Remind approver') }}</flux:button>
                    </flux:modal.trigger>
                @endif
            @endcan
        </div>
    </div>

    <flux:modal name="nudge-modal">
        <form wire:submit="sendNudge" class="space-y-4">
            <div>
                <flux:heading size="md">{{ __('Send a reminder') }}</flux:heading>
                <flux:subheading>{{ __('The current approvers are notified. Quiet hours and rate limits still apply.') }}</flux:subheading>
            </div>
            <flux:textarea wire:model="nudgeMessage" :label="__('Note (optional)')" rows="3" />
            <flux:error name="nudgeMessage" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="send-nudge">{{ __('Send reminder') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:card>
        <dl class="grid gap-4 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-zinc-500">{{ __('Type') }}</dt><dd class="font-medium">{{ ucfirst($submission->type->value) }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('Submitted by') }}</dt><dd class="font-medium">{{ $submission->submitter->name }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('Department') }}</dt><dd class="font-medium">{{ $submission->department?->name ?? '—' }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('Due date') }}</dt><dd class="font-medium">{{ $submission->due_date?->format('d M Y') ?? '—' }}</dd></div>
        </dl>
    </flux:card>

    @if ($submission->revisions->isNotEmpty() || $submission->fileRevisions->isNotEmpty())
        <flux:card data-test="request-history">
            <flux:heading size="sm">{{ __('Request history') }}</flux:heading>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($submission->revisions as $revision)
                    <li>
                        <span class="font-medium">{{ $revision->summary }}</span>
                        <span class="text-zinc-500"> · {{ $revision->actor?->name ?? __('System') }} · {{ $revision->created_at?->format('d M Y H:i') }}</span>
                        @if (is_array($revision->before))
                            <div class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                                @foreach ($revision->before as $field => $old)
                                    <div>{{ str_replace('_', ' ', $field) }}: {{ $old }} → {{ $revision->after[$field] ?? '' }}</div>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
                @foreach ($submission->fileRevisions as $fileRevision)
                    <li>{{ $fileRevision->action }} · {{ $fileRevision->original_name }}</li>
                @endforeach
            </ul>
        </flux:card>
    @endif

    @if (! $submission->requestTypeVersion->requestType->is_system)
        @php
            $summaryFields = $this->visibleCustomFields->where('is_approver_summary', true);
        @endphp
        @if ($this->canAct && $summaryFields->isNotEmpty())
            <flux:card class="border-blue-200 bg-blue-50/50 dark:border-blue-900 dark:bg-blue-950/20">
                <flux:heading size="sm">{{ __('Approval summary') }}</flux:heading>
                <dl class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($summaryFields as $field)
                        @php
                            $stored = $submission->fieldValues->firstWhere('request_field_definition_id', $field->id);
                        @endphp
                        <div><dt class="text-xs text-zinc-500">{{ $field->label }}</dt><dd class="font-medium">{{ app(\App\Services\RequestFieldAccess::class)->displayValue($stored) }}</dd></div>
                    @endforeach
                </dl>
            </flux:card>
        @endif
        <flux:card class="print:shadow-none">
            <flux:heading size="sm">{{ $submission->requestTypeVersion->requestType->name }} · {{ __('Version') }} {{ $submission->requestTypeVersion->version }}</flux:heading>
            <dl class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->visibleCustomFields as $field)
                    @php $stored = $submission->fieldValues->firstWhere('request_field_definition_id', $field->id); @endphp
                    <div class="grid gap-1 py-3 text-sm sm:grid-cols-3">
                        <dt class="font-medium">{{ $field->label }}</dt>
                        <dd class="sm:col-span-2">
                            @if (! $stored) —
                            @elseif ($field->type === \App\Enums\RequestFieldType::User) {{ $stored->userValue?->name ?? '—' }}
                            @elseif ($field->type === \App\Enums\RequestFieldType::Department) {{ $stored->departmentValue?->name ?? '—' }}
                            @elseif (is_array($stored->resolvedValue())) {{ implode(', ', $stored->resolvedValue()) }}
                            @else {{ $stored->resolvedValue() }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </flux:card>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card>
            <flux:heading size="sm">{{ __('Workflow progress') }}</flux:heading>
            @if ($submission->workflowInstance?->selection_explanation)
                <p class="mt-1 text-sm text-zinc-500" data-test="selection-explanation">{{ $submission->workflowInstance->selection_explanation }}</p>
            @endif
            @if ($this->expectedProgress)
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2" data-test="expected-progress">
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Current step') }}</dt>
                        <dd class="font-medium">{{ $this->expectedProgress['current_step'] ?? '—' }}</dd>
                        <dd class="text-xs text-zinc-500">{{ $this->expectedProgress['current_assignees'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Expected next step') }}</dt>
                        <dd class="font-medium" data-test="expected-next-step">{{ $this->expectedProgress['next_step'] ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-zinc-500">{{ __('Estimated due') }}</dt>
                        <dd class="font-medium" data-test="estimated-due">
                            @if ($this->expectedProgress['estimated_due_at'])
                                {{ $this->expectedProgress['estimated_due_at']->timezone(config('app.timezone'))->format('d M Y H:i') }}
                                <span class="text-xs font-normal text-zinc-500">({{ app(\App\Services\Collaboration\ExpectedProgress::class)->calendarLabel($this->expectedProgress['calendar']) }})</span>
                            @else
                                {{ __('No step deadline') }}
                            @endif
                        </dd>
                    </div>
                </dl>
            @endif

            @if (count($this->timeline))
                <ol class="mt-4 space-y-0">
                    @foreach ($this->timeline as $step)
                        @php($styles = ['pending' => 'bg-zinc-400', 'active' => 'bg-blue-500', 'done' => 'bg-green-500', 'rejected' => 'bg-red-500'])
                        <li class="relative flex gap-4 pb-6 last:pb-0">
                            @if (! $loop->last)<div class="absolute top-4 bottom-0 w-px bg-zinc-200 dark:bg-zinc-700" style="left: 11px"></div>@endif
                            <span class="z-10 mt-1 h-6 w-6 shrink-0 rounded-full border-4 border-white dark:border-zinc-900 {{ $styles[$step['state']] ?? 'bg-zinc-400' }}"></span>
                            <div>
                                <p class="text-sm font-medium">{{ $loop->iteration }}. {{ $step['name'] }}</p>
                                <p class="text-xs text-zinc-500">{{ $step['actors'] }}</p>
                                @if ($step['progress'])<p class="text-xs text-zinc-500">{{ $step['progress'] }}</p>@endif
                                @if ($step['acted_at'])<p class="text-xs text-zinc-400">{{ $step['acted_at'] }}</p>@endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="mt-3 text-sm text-zinc-500">{{ __('Not yet submitted for approval.') }}</p>
            @endif
        </flux:card>

        <flux:card>
            <flux:heading size="sm">{{ __('Action history') }}</flux:heading>

            @if ($submission->workflowInstance?->actions->isNotEmpty())
                <ul class="mt-4 space-y-3">
                    @foreach ($submission->workflowInstance->actions as $entry)
                        <li class="text-sm">
                            <span class="font-medium">{{ $entry->actor?->name ?? __('System') }}</span>
                            <flux:badge class="mx-1" color="{{ ['approve' => 'lime', 'auto_approve' => 'lime', 'condition_matched' => 'blue', 'condition_not_matched' => 'zinc', 'reject' => 'red', 'return' => 'amber', 'comment' => 'zinc', 'delegate' => 'blue', 'escalate' => 'blue'][$entry->action->value] ?? 'zinc' }}">{{ str($entry->action->value)->replace('_', ' ') }}</flux:badge>
                            <span class="text-xs text-zinc-400">{{ $entry->created_at?->format('d M Y H:i') }}</span>
                            @if ($entry->comment)<p class="mt-1 rounded-md bg-zinc-50 p-2 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $entry->comment }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-zinc-500">{{ __('No actions recorded yet.') }}</p>
            @endif
        </flux:card>
    </div>

    @if ($submission->body)
        <flux:card>
            <flux:heading size="sm">{{ __('Body') }}</flux:heading>
            <div class="prose prose-sm mt-3 max-w-none dark:prose-invert">{!! app(\App\Services\RichTextSanitizer::class)->sanitize($submission->body) !!}</div>
        </flux:card>
    @endif

    @if ($submission->files->isNotEmpty())
        <flux:card>
            <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($submission->files as $file)
                    <li class="flex items-center justify-between gap-3">
                        <span>📎 {{ $file->original_name }} <span class="text-xs text-zinc-400">({{ number_format($file->size_bytes / 1024) }} KB)</span></span>
                        <span class="flex items-center gap-3">
                            @if ($file->isPreviewable())
                                <flux:button size="xs" variant="ghost" wire:click="openPreview({{ $file->id }})">{{ __('Preview') }}</flux:button>
                            @endif
                            <flux:link :href="$this->fileUrl($file)" target="_blank">{{ __('Download') }}</flux:link>
                        </span>
                    </li>
                @endforeach
            </ul>
        </flux:card>

        <flux:modal name="file-preview" class="w-full max-w-5xl">
            @if ($this->previewFile)
                <flux:heading size="md">{{ $this->previewFile->original_name }}</flux:heading>
                <div class="mt-4 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-900">
                    @if (str_starts_with($this->previewFile->mime_type, 'image/'))
                        <img src="{{ $this->previewUrl($this->previewFile) }}" alt="{{ $this->previewFile->original_name }}" class="mx-auto max-h-[70vh] object-contain" />
                    @else
                        <iframe src="{{ $this->previewUrl($this->previewFile) }}" title="{{ $this->previewFile->original_name }}" class="h-[70vh] w-full"></iframe>
                    @endif
                </div>
            @endif
        </flux:modal>
    @endif

    @if ($this->canAct)
        <flux:card class="sticky bottom-3 z-20 border-blue-200 bg-white/95 shadow-xl backdrop-blur dark:border-blue-900 dark:bg-zinc-900/95">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="sm">{{ __('Your decision') }}</flux:heading>
                    <flux:subheading>{{ __('Shortcuts: [ previous, ] next, A approve, R reject') }}</flux:subheading>
                </div>
                <div class="flex gap-2">
                    @if ($this->approvalNavigation['previous'])
                        <flux:button :href="$this->approvalNavigation['previous']" variant="ghost" wire:navigate aria-label="{{ __('Previous request') }}">{{ __('Previous') }}</flux:button>
                    @endif
                    @if ($this->approvalNavigation['next'])
                        <flux:button :href="$this->approvalNavigation['next']" variant="ghost" wire:navigate aria-label="{{ __('Next request') }}">{{ __('Next') }}</flux:button>
                    @endif
                </div>
            </div>
            <flux:textarea wire:model="actionComment" :label="__('Comment (required for reject, return, information request, or escalation)')" rows="3" class="mt-3" />
            <flux:error name="actionComment" />

            <div class="mt-4 flex flex-wrap gap-3">
                <flux:modal.trigger name="approve-modal">
                    <flux:button variant="primary" data-test="approve-modal-trigger" data-requires-online>{{ __('Approve') }}</flux:button>
                </flux:modal.trigger>
                <flux:button variant="primary" wire:click="act('approve_with_conditions')" data-test="approve-with-conditions" data-requires-online>{{ __('Approve with conditions') }}</flux:button>
                @foreach (app(\App\Services\Workflow\StepOutcomes::class)->forVersion($submission->workflowInstance?->templateVersion) as $outcome)
                    @if ($outcome['custom'])
                        <flux:button variant="subtle" wire:click="act('{{ $outcome['key'] }}')" data-test="outcome-{{ $outcome['key'] }}">{{ $outcome['label'] }}</flux:button>
                    @endif
                @endforeach
                <flux:modal.trigger name="reject-modal">
                    <flux:button variant="danger" data-test="reject-modal-trigger" data-requires-online>{{ __('Reject') }}</flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="return-modal">
                    <flux:button variant="subtle" data-test="return">{{ __('Return for revision') }}</flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="information-modal">
                    <flux:button variant="subtle" data-test="request-information">{{ __('Request information') }}</flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="escalate-modal">
                    <flux:button variant="ghost" data-test="escalate">{{ __('Escalate') }}</flux:button>
                </flux:modal.trigger>
                @if ($this->canDelegate)
                    <flux:modal.trigger name="delegate-modal">
                        <flux:button variant="ghost" icon="user-plus">{{ __('Delegate') }}</flux:button>
                    </flux:modal.trigger>
                @endif
            </div>

            <flux:modal name="approve-modal">
                <flux:heading size="md">{{ __('Approve this submission?') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Your decision is recorded in the immutable audit trail and may advance the workflow.') }}</flux:subheading>
                <div class="mt-4 flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="act('approve')" data-test="approve" data-requires-online>{{ __('Confirm approval') }}</flux:button>
                </div>
            </flux:modal>

            <flux:modal name="reject-modal" variant="danger">
                <flux:heading size="md">{{ __('Reject this submission?') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('The workflow terminates and the submitter is notified with your comment.') }}</flux:subheading>
                <flux:textarea wire:model="actionComment" :label="__('Reason (required)')" rows="3" class="mt-4" dusk="reject-comment" />
                <div class="mt-4 flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="act('reject')" data-requires-online>{{ __('Confirm rejection') }}</flux:button>
                </div>
            </flux:modal>

            <flux:modal name="delegate-modal">
                <form wire:submit="delegateApproval" class="space-y-4">
                    <div>
                        <flux:heading size="md">{{ __('Delegate this approval') }}</flux:heading>
                        <flux:subheading>{{ __('Your decision authority transfers until the selected expiry.') }}</flux:subheading>
                    </div>
                    <flux:select wire:model="delegateUserId" :label="__('Delegate')">
                        <flux:select.option value="">{{ __('Select a user') }}</flux:select.option>
                        @foreach ($this->delegateCandidates as $candidate)
                            <flux:select.option :value="$candidate->id">{{ $candidate->name }} — {{ $candidate->email }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="delegateUserId" />
                    <flux:input wire:model="delegationStartsAt" type="datetime-local" :label="__('Starts at (optional)')" :description="__('Leave empty to start immediately. A future start keeps your authority until then.')" />
                    <flux:error name="delegationStartsAt" />
                    <flux:input wire:model="delegationExpiresAt" type="datetime-local" :label="__('Expires at')" />
                    <flux:textarea wire:model="delegationComment" :label="__('Note (optional)')" rows="2" />
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ __('Delegate approval') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="return-modal">
                <flux:heading size="md">{{ __('Return for revision?') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('The submitter revises and resubmits; the approval chain resumes where it paused.') }}</flux:subheading>
                <flux:textarea wire:model="actionComment" :label="__('What should be revised? (required)')" rows="3" class="mt-4" />
                <div class="mt-4 flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="subtle" wire:click="act('return')">{{ __('Confirm return') }}</flux:button>
                </div>
            </flux:modal>

            <flux:modal name="information-modal">
                <flux:heading size="md">{{ __('Request more information?') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('The workflow pauses at this step until the submitter responds and resubmits.') }}</flux:subheading>
                <flux:textarea wire:model="actionComment" :label="__('What information is required?')" rows="3" class="mt-4" />
                <div class="mt-4 flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="subtle" wire:click="act('request_information')">{{ __('Send request') }}</flux:button>
                </div>
            </flux:modal>

            <flux:modal name="escalate-modal">
                <flux:heading size="md">{{ __('Escalate to the next step?') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Your current step will be bypassed and the next configured approvers will be notified.') }}</flux:subheading>
                <flux:textarea wire:model="actionComment" :label="__('Reason for escalation')" rows="3" class="mt-4" />
                <div class="mt-4 flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="act('escalate')">{{ __('Confirm escalation') }}</flux:button>
                </div>
            </flux:modal>
        </flux:card>
    @endif

    <flux:card data-test="watchers-card">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="sm">{{ __('Followers') }}</flux:heading>
                <flux:subheading>{{ __('Followers can open this request. You can unfollow yourself at any time.') }}</flux:subheading>
            </div>
            @if ($submission->isWatchedBy(auth()->user()))
                <flux:button size="sm" variant="ghost" wire:click="unfollow" data-test="unfollow">{{ __('Unfollow') }}</flux:button>
            @else
                <flux:button size="sm" variant="subtle" wire:click="follow" data-test="follow">{{ __('Follow') }}</flux:button>
            @endif
        </div>

        @if ($submission->isWatchedBy(auth()->user()))
            <form wire:submit="saveWatcherPreferences" class="mt-3 flex flex-wrap items-end gap-3">
                <flux:checkbox wire:model="notifyComments" :label="__('Notify me of comments')" />
                <flux:checkbox wire:model="notifyStatus" :label="__('Notify me of status changes')" />
                <flux:button type="submit" size="xs" variant="ghost">{{ __('Save') }}</flux:button>
            </form>
        @endif

        <ul class="mt-3 flex flex-wrap gap-2" data-test="watcher-list">
            @forelse ($submission->watchers as $watcher)
                <li class="inline-flex items-center gap-1 rounded-full border border-zinc-300 bg-zinc-50 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-800">
                    {{ $watcher->user->name }}
                    @if (auth()->id() === $watcher->user_id || auth()->user()?->can('addWatcher', $submission))
                        <button type="button" class="text-zinc-500" wire:click="removeWatcher({{ $watcher->user_id }})" aria-label="{{ __('Remove :name', ['name' => $watcher->user->name]) }}">×</button>
                    @endif
                </li>
            @empty
                <li class="text-sm text-zinc-500">{{ __('No followers yet.') }}</li>
            @endforelse
        </ul>

        @can('addWatcher', $submission)
            <div class="mt-4">
                <x-ui.assignee-picker
                    type="user"
                    query="watcherQuery"
                    :selected="collect()"
                    :results="$this->watcherResults"
                    add="addWatcher"
                    remove="removeWatcher"
                    :hide-empty="true"
                    placeholder="Type a name to add a follower…"
                />
            </div>
        @endcan
    </flux:card>

    <flux:card>
        <flux:heading size="sm">{{ __('Comments') }}</flux:heading>

        <ul class="mt-4 space-y-4">
            @forelse ($this->commentThreads as $c)
                <li class="text-sm" data-test="comment-{{ $c->id }}">
                    <span class="font-medium">{{ $c->author->name }}</span>
                    <span class="text-xs text-zinc-400">{{ $c->created_at->format('d M Y H:i') }}</span>
                    @if ($c->edited_at)
                        <span class="text-xs text-zinc-400">{{ __('(edited)') }}</span>
                    @endif
                    @if ($this->editingCommentId === $c->id)
                        <form wire:submit="saveEdit" class="mt-2 space-y-2">
                            <flux:textarea wire:model="editBody" rows="2" />
                            <flux:error name="editBody" />
                            <div class="flex gap-2">
                                <flux:button type="submit" size="xs" variant="primary">{{ __('Save') }}</flux:button>
                                <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <p class="mt-1 rounded-md bg-zinc-50 p-2 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $this->displayComment($c) }}</p>
                    @endif
                    @if ($c->revisions->isNotEmpty())
                        <details class="mt-1 text-xs text-zinc-500">
                            <summary>{{ __('Edit history') }}</summary>
                            <ul class="mt-1 space-y-1">
                                @foreach ($c->revisions as $revision)
                                    <li>{{ $revision->created_at->format('d M Y H:i') }}: {{ $revision->body }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                    <div class="mt-1 flex gap-2">
                        @can('comment', $submission)
                            <flux:button size="xs" variant="ghost" wire:click="startReply({{ $c->id }})" data-test="reply-{{ $c->id }}">{{ __('Reply') }}</flux:button>
                        @endcan
                        @can('update', $c)
                            <flux:button size="xs" variant="ghost" wire:click="startEdit({{ $c->id }})" data-test="edit-comment-{{ $c->id }}">{{ __('Edit') }}</flux:button>
                        @endcan
                    </div>
                    @if ($c->replies->isNotEmpty())
                        <ul class="mt-2 space-y-2 border-l border-zinc-200 pl-3 dark:border-zinc-700">
                            @foreach ($c->replies as $reply)
                                <li>
                                    <span class="font-medium">{{ $reply->author->name }}</span>
                                    <span class="text-xs text-zinc-400">{{ $reply->created_at->format('d M Y H:i') }}</span>
                                    @if ($reply->edited_at)
                                        <span class="text-xs text-zinc-400">{{ __('(edited)') }}</span>
                                    @endif
                                    @if ($this->editingCommentId === $reply->id)
                                        <form wire:submit="saveEdit" class="mt-2 space-y-2">
                                            <flux:textarea wire:model="editBody" rows="2" />
                                            <div class="flex gap-2">
                                                <flux:button type="submit" size="xs" variant="primary">{{ __('Save') }}</flux:button>
                                                <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                                            </div>
                                        </form>
                                    @else
                                        <p class="mt-1 rounded-md bg-zinc-50 p-2 dark:bg-zinc-800">{{ $this->displayComment($reply) }}</p>
                                    @endif
                                    @can('update', $reply)
                                        <flux:button size="xs" variant="ghost" wire:click="startEdit({{ $reply->id }})">{{ __('Edit') }}</flux:button>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($this->replyParentId === $c->id)
                        <form wire:submit="postReply" class="mt-2 space-y-2">
                            <flux:textarea wire:model="replyBody" :placeholder="__('Write a reply…')" rows="2" />
                            <flux:error name="replyBody" />
                            <div class="flex gap-2">
                                <flux:button type="submit" size="xs" variant="primary" data-test="submit-reply">{{ __('Reply') }}</flux:button>
                                <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @endif
                </li>
            @empty
                <li class="text-sm text-zinc-500">{{ __('No comments yet.') }}</li>
            @endforelse
        </ul>

        @can('comment', $submission)
            <form wire:submit="postComment" class="mt-4 space-y-2">
                <flux:textarea wire:model="comment" :placeholder="__('Add a comment. Use search below to mention someone…')" rows="2" />
                <flux:error name="comment" />
                <x-ui.assignee-picker
                    type="user"
                    query="mentionQuery"
                    :selected="$this->mentionedUsers"
                    :results="$this->mentionResults"
                    add="addMention"
                    remove="removeMention"
                    placeholder="Type two characters to mention someone…"
                />
                <flux:button type="submit" variant="ghost" size="sm">{{ __('Comment') }}</flux:button>
            </form>
        @endcan
    </flux:card>

    @can('withdraw', $submission)
        <flux:button variant="ghost" wire:click="withdraw" wire:confirm="{{ __('Withdraw this submission? The approval chain will be cancelled.') }}">
            {{ __('Withdraw') }}
        </flux:button>
    @endcan

    @can('submit', $submission)
        @if (in_array($submission->status->value, ['returned', 'awaiting_information'], true))
            <div class="space-y-3">
                @if ($submission->status->value === 'awaiting_information')
                    <flux:textarea wire:model="resubmissionComment" :label="__('Response to the information request')" rows="3" />
                    <flux:error name="resubmissionComment" />
                @endif
                <flux:button variant="primary" wire:click="resubmit" data-test="resubmit">{{ __('Resubmit for approval') }}</flux:button>
            </div>
        @endif
    @endcan
</div>
