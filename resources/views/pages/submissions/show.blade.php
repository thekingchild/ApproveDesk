<?php

use App\Actions\Collaboration\NudgeApproversAction;
use App\Actions\Submissions\ResubmitSubmissionAction;
use App\Exceptions\CollaborationException;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Services\Collaboration\ExpectedProgress;
use App\Services\Workflow\WorkflowTimeline;
use App\Services\WorkflowEngine;
use App\Support\RichTextSanitizer;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The request page. The approver's decision panel, comments, followers and
 * attachments are child components under livewire/submissions (ADR 0006
 * split, RV-ARC-02); this page shows the request, its progress and history,
 * and the requester's withdraw / resubmit and reminder actions.
 */
new #[Title('Submission')] class extends Component {
    public Submission $submission;

    public string $nudgeMessage = '';

    public string $resubmissionComment = '';

    /** Relations the page renders; reloaded on every request because Livewire rehydrates the model without them. */
    private const RELATIONS = [
            'submitter', 'department', 'files.uploader', 'removedFiles.remover:id,name',
            'requestTypeVersion.requestType', 'requestTypeVersion.fields',
            'fieldValues.field', 'fieldValues.userValue', 'fieldValues.departmentValue',
            'workflowInstance.template.steps',
            'workflowInstance.templateVersion.steps',
            'workflowInstance.currentStep',
            'workflowInstance.actions.actor',
            'workflowInstance.actions.onBehalfOf:id,name',
            'revisions.actor:id,name',
            'fileRevisions',
            'submitter.manager',
            'submitter.department.manager',
            'department.manager',
    ];

    public function hydrate(): void
    {
        $this->submission->loadMissing(self::RELATIONS);
    }

    public function mount(Submission $submission): void
    {
        $this->authorize('view', $submission);
        $this->submission = $submission->load(self::RELATIONS);
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

    /** Per-step progress of the approval chain (WorkflowTimeline, RV-ARC-02). */
    #[Computed]
    public function timeline(): array
    {
        return app(WorkflowTimeline::class)->for($this->submission);
    }

    #[Computed]
    public function canAct(): bool
    {
        $instance = $this->submission->workflowInstance;

        return $instance !== null && app(WorkflowEngine::class)->canAct($instance, Auth::user());
    }

    public function sendNudge(): void
    {
        $this->authorize('nudge', $this->submission);
        try {
            $result = app(NudgeApproversAction::class)->execute(
                $this->submission,
                Auth::user(),
                $this->nudgeMessage !== '' ? $this->nudgeMessage : null,
            );
        } catch (CollaborationException $exception) {
            $this->addError('nudgeMessage', $exception->userMessage());

            return;
        }

        $this->reset('nudgeMessage');
        Flux::toast(
            $result->skipped->isEmpty()
                ? __('Reminder sent.')
                : __('Reminder sent. Not reminded again yet: :names.', ['names' => $result->skipped->pluck('name')->join(', ')]),
            variant: 'success',
        );
    }

    /** Revision history with editors; loaded here so it survives Livewire rehydration. */
    #[Computed]
    public function revisionHistory(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->submission->revisions()->with('actor:id,name')->get();
    }

    #[Computed]
    public function expectedProgress(): ?array
    {
        return app(ExpectedProgress::class)->for($this->submission);
    }

    public function withdraw(): void
    {
        $this->authorize('withdraw', $this->submission);

        try {
            app(\App\Actions\Submissions\WithdrawSubmissionAction::class)->execute($this->submission, Auth::user());
        } catch (WorkflowException) {
            Flux::toast(__('This request has already been decided, so it can no longer be withdrawn.'), variant: 'danger');
            $this->redirect(route('submissions.show', $this->submission), navigate: true);

            return;
        }

        Flux::toast('Submission withdrawn.', variant: 'success');

        $this->redirect(route('submissions.show', $this->submission), navigate: true);
    }

    public function resubmit(): void
    {
        $this->authorize('submit', $this->submission);

        $awaitingInformation = $this->submission->status === \App\Enums\SubmissionStatus::AwaitingInformation;
        $comment = $awaitingInformation
            ? $this->validate(['resubmissionComment' => ['required', 'string', 'max:5000']])['resubmissionComment']
            : null;

        try {
            app(ResubmitSubmissionAction::class)->execute($this->submission, Auth::user(), $comment);
        } catch (WorkflowException $exception) {
            $message = __('This request could not be sent for approval: :reason', ['reason' => $exception->userMessage()]);
            if ($comment !== null) {
                $this->addError('resubmissionComment', $message);
            }
            Flux::toast($message, variant: 'danger');

            return;
        }

        Flux::toast('Resubmitted for approval.', variant: 'success');

        $this->redirect(route('submissions.show', $this->submission), navigate: true);
    }

    #[Computed]
    public function expectedCalendarLabel(): ?string
    {
        return $this->expectedProgress === null ? null : app(ExpectedProgress::class)->calendarLabel($this->expectedProgress['calendar']);
    }

    protected function safeHtml(mixed $html): string
    {
        return app(RichTextSanitizer::class)->sanitize(is_scalar($html) ? (string) $html : '');
    }

    protected function fieldDisplayValue(?\App\Models\SubmissionFieldValue $stored): string
    {
        return app(\App\Services\RequestFieldAccess::class)->displayValue($stored);
    }
} ?>

<div
    class="space-y-6"
    x-data="approvalShortcuts"
    data-previous-url="{{ $this->canAct ? route('approvals.queue', [$submission, 'previous']) : '' }}"
    data-next-url="{{ $this->canAct ? route('approvals.queue', [$submission, 'next']) : '' }}"
    x-on:keydown.window="handle($event)"
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
            <x-submission-status-badge :status="$submission->status" />
            <x-submission-priority-badge :priority="$submission->priority" />
            @if ($submission->workflowInstance?->deadlineAt())
                <flux:badge :color="$submission->workflowInstance->isOverdue() ? 'red' : 'zinc'">
                    {{ $submission->workflowInstance->isOverdue()
                        ? __('Overdue')
                        : __('Due').' '.$submission->workflowInstance->deadlineAt()->diffForHumans() }}
                </flux:badge>
            @endif
            @can('nudge', $submission)
                @if ($submission->workflowInstance?->status === \App\Enums\WorkflowStatus::Active)
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
            <div><dt class="text-xs text-zinc-500">{{ __('Due date') }}</dt><dd class="font-medium">{{ $submission->due_date?->format(\App\Support\LocalTime::FORMATS['date']) ?? '—' }}</dd></div>
        </dl>
    </flux:card>

    @if ($this->revisionHistory->isNotEmpty() || $submission->fileRevisions->isNotEmpty())
        <flux:card data-test="request-history">
            <flux:heading size="sm">{{ __('Request history') }}</flux:heading>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($this->revisionHistory as $revision)
                    <li>
                        <span class="font-medium">{{ $revision->summary }}</span>
                        <span class="text-zinc-500"> · {{ $revision->actor?->name ?? __('System') }} · <x-time :at="$revision->created_at" /></span>
                        @if (is_array($revision->before))
                            <div class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                                @foreach ($revision->before as $field => $old)
                                    @if ($field === 'body')
                                        @php
                                            $beforeBody = $this->safeHtml($old ?? '');
                                            $afterBody = $this->safeHtml($revision->after[$field] ?? '');
                                        @endphp
                                        <details class="mt-2" wire:key="body-revision-{{ $revision->id }}">
                                            <summary class="cursor-pointer font-medium">{{ __('View body changes') }}</summary>
                                            <dl class="mt-3 grid min-w-0 gap-4 sm:grid-cols-2">
                                                <div class="min-w-0">
                                                    <dt class="font-medium">{{ __('Before') }}</dt>
                                                    <dd class="prose prose-sm mt-1 max-w-none overflow-x-auto break-words dark:prose-invert" data-test="body-before">{!! $beforeBody !== '' ? $beforeBody : e(__('No body')) !!}</dd>
                                                </div>
                                                <div class="min-w-0">
                                                    <dt class="font-medium">{{ __('After') }}</dt>
                                                    <dd class="prose prose-sm mt-1 max-w-none overflow-x-auto break-words dark:prose-invert" data-test="body-after">{!! $afterBody !== '' ? $afterBody : e(__('No body')) !!}</dd>
                                                </div>
                                            </dl>
                                        </details>
                                    @else
                                        <div>{{ str_replace('_', ' ', $field) }}: {{ $old }} → {{ $revision->after[$field] ?? '' }}</div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
                @foreach ($submission->fileRevisions as $fileRevision)
                    <li>{{ $fileRevision->action->label() }} · {{ $fileRevision->original_name }}</li>
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
                        <div><dt class="text-xs text-zinc-500">{{ $field->label }}</dt><dd class="font-medium">{{ $this->fieldDisplayValue($stored) }}</dd></div>
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
                                <x-time :at="$this->expectedProgress['estimated_due_at']" />
                                <span class="text-xs font-normal text-zinc-500">({{ $this->expectedCalendarLabel }})</span>
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
                        <li class="relative flex gap-4 pb-6 last:pb-0">
                            @if (! $loop->last)<div class="absolute top-4 bottom-0 w-px bg-zinc-200 dark:bg-zinc-700" style="left: 11px"></div>@endif
                            <span class="z-10 mt-1 h-6 w-6 shrink-0 rounded-full border-4 border-white dark:border-zinc-900 {{ $step['state']->dotClass() }}"></span>
                            <div>
                                <p class="text-sm font-medium">{{ $loop->iteration }}. {{ $step['name'] }}</p>
                                <p class="text-xs text-zinc-500">{{ $step['actors'] }}</p>
                                @if ($step['required'] !== null)<p class="text-xs text-zinc-500">{{ __(':done / :required approvals', ['done' => $step['approvals'], 'required' => $step['required']]) }}</p>@endif
                                @if ($step['acted_at'])<p class="text-xs text-zinc-400">{{ \App\Support\LocalTime::forViewer($step['acted_at']) }}</p>@endif
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
                            @if ($entry->on_behalf_of_id)
                                <span class="text-zinc-500" data-test="on-behalf-of">{{ __('on behalf of :name', ['name' => $entry->onBehalfOf?->name ?? __('a former user')]) }}</span>
                            @endif
                            <flux:badge class="mx-1" color="{{ $entry->action->color() }}">{{ $entry->action->label() }}</flux:badge>
                            <span class="text-xs text-zinc-400"><x-time :at="$entry->created_at" /></span>
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
            <div class="prose prose-sm mt-3 max-w-none dark:prose-invert">{!! $this->safeHtml($submission->body) !!}</div>
        </flux:card>
    @endif

    @if ($submission->files->isNotEmpty() || $submission->removedFiles->isNotEmpty())
        <livewire:submissions.attachments :submission="$submission" wire:key="submission-attachments" />
    @endif

    <livewire:submissions.decision :submission="$submission" wire:key="submission-decision" />

    <livewire:submissions.followers :submission="$submission" wire:key="submission-followers" />

    <livewire:submissions.comments :submission="$submission" wire:key="submission-comments" />

    @can('withdraw', $submission)
        <flux:button variant="ghost" wire:click="withdraw" wire:confirm="{{ __('Withdraw this submission? The approval chain will be cancelled.') }}">
            {{ __('Withdraw') }}
        </flux:button>
    @endcan

    @can('submit', $submission)
        @if ($submission->status->needsRequester())
            <div class="space-y-3">
                @if ($submission->status === \App\Enums\SubmissionStatus::AwaitingInformation)
                    <flux:textarea wire:model="resubmissionComment" :label="__('Response to the information request')" rows="3" />
                    <flux:error name="resubmissionComment" />
                @endif
                <flux:button variant="primary" wire:click="resubmit" data-test="resubmit">{{ __('Resubmit for approval') }}</flux:button>
            </div>
        @endif
    @endcan
</div>
