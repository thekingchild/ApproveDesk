<?php

use App\Enums\WorkflowActionType;
use App\Exceptions\WorkflowException;
use App\Models\Comment;
use App\Models\Submission;
use App\Services\WorkflowEngine;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Submission')] class extends Component {
    public Submission $submission;

    public string $comment = '';

    public string $actionComment = '';

    public function mount(Submission $submission): void
    {
        $this->authorize('view', $submission);
        $this->submission = $submission->load([
            'submitter', 'department', 'files.uploader',
            'workflowInstance.template.steps',
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

            $state = match (true) {
                $stepActions->contains('action', WorkflowActionType::Reject) => 'rejected',
                $stepActions->contains('action', WorkflowActionType::Approve) => 'done',
                $instance->current_step_id === $step->id && $instance->status->value === 'active' => 'active',
                default => 'pending',
            };

            return [
                'name' => $step->name,
                'order' => $step->step_order,
                'state' => $state,
                'actors' => $step->assignedUsers()->pluck('name')->implode(', '),
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

        if ($step === null || ! $step->assignedUsers()->contains($user)) {
            return false;
        }

        return ! $instance->actions
            ->where('step_id', $step->id)
            ->where('actor_id', $user->id)
            ->whereIn('action', ['approve', 'reject', 'return'])
            ->isNotEmpty();
    }

    public function act(string $action): void
    {
        $this->resetValidation();

        if (in_array($action, ['reject', 'return'], true) && trim($this->actionComment) === '') {
            $this->addError('actionComment', 'A comment is required when rejecting or returning.');

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
} ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ $submission->title }}</flux:heading>
            <flux:subheading class="font-mono">{{ $submission->reference_number }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:badge color="{{ ['draft' => 'zinc', 'in_review' => 'blue', 'approved' => 'lime', 'rejected' => 'red', 'returned' => 'amber', 'withdrawn' => 'zinc', 'pending' => 'blue'][$submission->status->value] ?? 'zinc' }}">
                {{ str_replace('_', ' ', $submission->status->value) }}
            </flux:badge>
            <flux:badge color="{{ ['low' => 'zinc', 'normal' => 'blue', 'high' => 'amber', 'urgent' => 'red'][$submission->priority->value] ?? 'zinc' }}">
                {{ $submission->priority->value }}
            </flux:badge>
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
                            <span class="font-medium">{{ $entry->actor->name }}</span>
                            <flux:badge class="mx-1" color="{{ ['approve' => 'lime', 'reject' => 'red', 'return' => 'amber', 'comment' => 'zinc', 'delegate' => 'blue', 'escalate' => 'blue'][$entry->action->value] ?? 'zinc' }}">{{ $entry->action->value }}</flux:badge>
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
            <div class="prose prose-sm mt-3 max-w-none whitespace-pre-wrap dark:prose-invert">{{ $submission->body }}</div>
        </flux:card>
    @endif

    @if ($submission->files->isNotEmpty())
        <flux:card>
            <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($submission->files as $file)
                    <li class="flex items-center justify-between gap-3">
                        <span>📎 {{ $file->original_name }} <span class="text-xs text-zinc-400">({{ number_format($file->size_bytes / 1024) }} KB)</span></span>
                        <flux:link :href="$this->fileUrl($file)" target="_blank">{{ __('Download') }}</flux:link>
                    </li>
                @endforeach
            </ul>
        </flux:card>
    @endif

    @if ($this->canAct)
        <flux:card class="border-blue-200 dark:border-blue-900">
            <flux:heading size="sm">{{ __('Your decision') }}</flux:heading>
            <flux:textarea wire:model="actionComment" :label="__('Comment (required for reject / return)')" rows="3" class="mt-3" />
            <flux:error name="actionComment" />

            <div class="mt-4 flex flex-wrap gap-3">
                <flux:button variant="primary" wire:click="act('approve')" data-test="approve">{{ __('Approve') }}</flux:button>
                <flux:modal.trigger name="reject-modal">
                    <flux:button variant="danger" data-test="reject">{{ __('Reject') }}</flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="return-modal">
                    <flux:button variant="subtle" data-test="return">{{ __('Return for revision') }}</flux:button>
                </flux:modal.trigger>
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

            <flux:modal name="return-modal">
                <flux:heading size="md">{{ __('Return for revision?') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('The submitter revises and resubmits; the approval chain resumes where it paused.') }}</flux:subheading>
                <flux:textarea wire:model="actionComment" :label="__('What should be revised? (required)')" rows="3" class="mt-4" />
                <div class="mt-4 flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="subtle" wire:click="act('return')">{{ __('Confirm return') }}</flux:button>
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
        @if ($submission->status->value === 'returned')
            <flux:button variant="primary" wire:click="resubmit" data-test="resubmit">{{ __('Resubmit for approval') }}</flux:button>
        @endif
    @endcan
</div>
