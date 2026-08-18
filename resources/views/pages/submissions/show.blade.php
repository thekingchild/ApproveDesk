<?php

use App\Enums\WorkflowActionType;
use App\Exceptions\WorkflowException;
use App\Models\Comment;
use App\Models\Delegation;
use App\Models\Submission;
use App\Models\User;
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

    public string $delegationExpiresAt = '';

    public string $delegationComment = '';

    public string $resubmissionComment = '';

    public ?int $previewFileId = null;

    public function mount(Submission $submission): void
    {
        $this->authorize('view', $submission);
        $this->submission = $submission->load([
            'submitter', 'department', 'files.uploader',
            'workflowInstance.template.steps',
            'workflowInstance.currentStep',
            'workflowInstance.actions.actor',
        ]);
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
            $assignedUsers = $step->assignedUsers();
            $automatic = in_array($step->step_type, [\App\Enums\StepType::Conditional, \App\Enums\StepType::Auto], true);
            $approvalCount = $stepActions->where('action', WorkflowActionType::Approve)->unique('actor_id')->count();
            $requiredApprovals = match ($step->step_type) {
                \App\Enums\StepType::Sequential => 1,
                \App\Enums\StepType::Parallel => $assignedUsers->count(),
                \App\Enums\StepType::Quorum => $step->quorum_count,
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
            'delegationExpiresAt' => ['required', 'date', 'after:now'],
            'delegationComment' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            app(WorkflowEngine::class)->delegate(
                $this->submission->workflowInstance,
                Auth::user(),
                User::query()->findOrFail((int) $validated['delegateUserId']),
                \Illuminate\Support\Carbon::parse($validated['delegationExpiresAt']),
                $validated['delegationComment'] ?: null,
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

        if (in_array($action, ['reject', 'return', 'request_information', 'escalate'], true) && trim($this->actionComment) === '') {
            $this->addError('actionComment', 'A comment is required for this action.');

            return;
        }

        try {
            app(WorkflowEngine::class)->processAction(
                $this->submission->workflowInstance,
                Auth::user(),
                WorkflowActionType::from($action),
                trim($this->actionComment) !== '' ? trim($this->actionComment) : null,
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

        Comment::create([
            'commentable_id' => $this->submission->id,
            'commentable_type' => Submission::class,
            'author_id' => Auth::id(),
            'body' => $this->comment,
        ]);

        $this->reset('comment');
        $this->submission->load('comments.author');
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

            Comment::create([
                'commentable_id' => $this->submission->id,
                'commentable_type' => Submission::class,
                'author_id' => Auth::id(),
                'body' => $validated['resubmissionComment'],
            ]);
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

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ $submission->title }}</flux:heading>
            <flux:subheading class="font-mono">{{ $submission->reference_number }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            @can('update', $submission)
                <flux:button :href="route('submissions.edit', $submission)" variant="primary" wire:navigate>{{ __('Edit draft') }}</flux:button>
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
        </div>
    </div>

    <flux:card>
        <dl class="grid gap-4 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-zinc-500">{{ __('Type') }}</dt><dd class="font-medium">{{ ucfirst($submission->type->value) }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('Submitted by') }}</dt><dd class="font-medium">{{ $submission->submitter->name }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('Department') }}</dt><dd class="font-medium">{{ $submission->department?->name ?? '—' }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('Due date') }}</dt><dd class="font-medium">{{ $submission->due_date?->format('d M Y') ?? '—' }}</dd></div>
        </dl>
    </flux:card>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card>
            <flux:heading size="sm">{{ __('Workflow progress') }}</flux:heading>

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
        <flux:card class="border-blue-200 dark:border-blue-900">
            <flux:heading size="sm">{{ __('Your decision') }}</flux:heading>
            <flux:textarea wire:model="actionComment" :label="__('Comment (required for reject, return, information request, or escalation)')" rows="3" class="mt-3" />
            <flux:error name="actionComment" />

            <div class="mt-4 flex flex-wrap gap-3">
                <flux:button variant="primary" wire:click="act('approve')" data-test="approve">{{ __('Approve') }}</flux:button>
                <flux:modal.trigger name="reject-modal">
                    <flux:button variant="danger" data-test="reject">{{ __('Reject') }}</flux:button>
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

            <flux:modal name="reject-modal" variant="danger">
                <flux:heading size="md">{{ __('Reject this submission?') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('The workflow terminates and the submitter is notified with your comment.') }}</flux:subheading>
                <flux:textarea wire:model="actionComment" :label="__('Reason (required)')" rows="3" class="mt-4" />
                <div class="mt-4 flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="act('reject')">{{ __('Confirm rejection') }}</flux:button>
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

    <flux:card>
        <flux:heading size="sm">{{ __('Comments') }}</flux:heading>

        <ul class="mt-4 space-y-3">
            @forelse ($submission->comments()->with('author')->latest('id')->get() as $c)
                <li class="text-sm">
                    <span class="font-medium">{{ $c->author->name }}</span>
                    <span class="text-xs text-zinc-400">{{ $c->created_at->format('d M Y H:i') }}</span>
                    <p class="mt-1 rounded-md bg-zinc-50 p-2 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $c->body }}</p>
                </li>
            @empty
                <li class="text-sm text-zinc-500">{{ __('No comments yet.') }}</li>
            @endforelse
        </ul>

        @can('comment', $submission)
            <form wire:submit="postComment" class="mt-4 space-y-2">
                <flux:textarea wire:model="comment" :placeholder="__('Add a comment…')" rows="2" />
                <flux:error name="comment" />
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
