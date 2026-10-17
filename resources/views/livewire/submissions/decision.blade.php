<?php

use App\DTOs\DelegationData;
use App\DTOs\WorkflowActionData;
use App\Enums\WorkflowActionType;
use App\Exceptions\WorkflowException;
use App\Models\Delegation;
use App\Models\User;
use App\Services\WorkflowEngine;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\SeparationOfDuties;
use App\Services\Workflow\StepOutcomes;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use App\Models\Submission;
use Livewire\Component;

/*
 * The approver's part of the request page (ADR 0006 split, RV-ARC-02): the
 * decision panel, delegation, and taking a delegation back. The workflow
 * engine decides who may act and explains refusals (ADR 0008).
 */
new class extends Component {
    public Submission $submission;

    /** Set from the browser (<x-browser-timezone />) so datetime-local values are read in the viewer's timezone. */
    public string $browserTimezone = '';

    public string $actionComment = '';

    public string $delegateUserId = '';

    public string $delegationStartsAt = '';

    public string $delegationExpiresAt = '';

    public string $delegationComment = '';

    /** The workflow step this page showed; decisions are refused once the request has moved on. */
    #[Locked]
    public ?int $renderedStepId = null;

    /** @var array<int, \Illuminate\Database\Eloquent\Collection<int, User>> Approvers per step for this render. */
    private array $assigneesByStep = [];

    public function mount(Submission $submission): void
    {
        $this->authorize('view', $submission);
        $this->submission = $submission->loadMissing(['workflowInstance.currentStep', 'workflowInstance.templateVersion']);
        $this->renderedStepId = $submission->workflowInstance?->current_step_id;
    }

    public function hydrate(): void
    {
        $this->submission->loadMissing(['workflowInstance.currentStep', 'workflowInstance.templateVersion']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    private function assigneesFor(\App\Models\WorkflowTemplateStep $step): \Illuminate\Database\Eloquent\Collection
    {
        return $this->assigneesByStep[$step->id] ??= $step->assignedUsers($this->submission);
    }

    #[Computed]
    public function canAct(): bool
    {
        $instance = $this->submission->workflowInstance;

        return $instance !== null && app(WorkflowEngine::class)->canAct($instance, Auth::user());
    }

    /** True when the viewer would be an approver on the current step but separation of duties excludes them. */
    #[Computed]
    public function selfApprovalBlocked(): bool
    {
        $step = $this->submission->workflowInstance?->currentStep;

        return $step !== null
            && $this->submission->workflowInstance->status === \App\Enums\WorkflowStatus::Active
            && SeparationOfDuties::forbids(Auth::user(), $this->submission)
            && app(AssigneeResolver::class)->candidates($step, $this->submission)->contains(Auth::user());
    }

    #[Computed]
    public function canDelegate(): bool
    {
        $instance = $this->submission->workflowInstance;

        return $instance !== null && app(WorkflowEngine::class)->canDelegate($instance, Auth::user());
    }

    #[Computed]
    public function delegateCandidates(): \Illuminate\Database\Eloquent\Collection
    {
        $step = $this->submission->workflowInstance?->currentStep;
        $excluded = $step !== null ? $this->assigneesFor($step)->pluck('id')->all() : [];

        if (SeparationOfDuties::enabled()) {
            $excluded[] = $this->submission->submitter_id;
        }

        return User::query()
            ->assignable()
            ->where('is_active', true)
            ->whereKeyNot(Auth::id())
            ->whereNotIn('id', $excluded)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /** The viewer's current or upcoming delegation on this step, if any (for "Take back"). */
    #[Computed]
    public function myDelegation(): ?Delegation
    {
        $instance = $this->submission->workflowInstance;
        if ($instance === null || $instance->status !== \App\Enums\WorkflowStatus::Active) {
            return null;
        }

        return Delegation::query()
            ->with('delegate:id,name')
            ->where('instance_id', $instance->id)
            ->where('step_id', $instance->current_step_id)
            ->where('delegator_id', Auth::id())
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    public function takeBackDelegation(): void
    {
        // The workflow engine decides eligibility and explains refusals (ADR 0008).
        $this->authorize('view', $this->submission);
        $delegation = $this->myDelegation;
        if ($delegation === null) {
            return;
        }

        app(WorkflowEngine::class)->revokeDelegation($delegation, Auth::user(), __('Taken back by the approver.'));
        Flux::toast(__('This step is yours again.'), variant: 'success');
        $this->redirect(route('submissions.show', $this->submission), navigate: true);
    }

    public function delegateApproval(): void
    {
        // The workflow engine decides eligibility and explains refusals (ADR 0008).
        $this->authorize('view', $this->submission);
        $validated = $this->validate([
            'delegateUserId' => ['required', 'integer', Rule::exists(User::class, 'id')->where('is_active', true)],
            'delegationStartsAt' => ['nullable', 'date'],
            'delegationExpiresAt' => ['required', 'date'],
            'delegationComment' => ['nullable', 'string', 'max:1000'],
        ]);

        $startsAt = filled($validated['delegationStartsAt'] ?? null)
            ? \App\Support\LocalTime::parse($validated['delegationStartsAt'], $this->browserTimezone)
            : now();
        $expiresAt = \App\Support\LocalTime::parse($validated['delegationExpiresAt'], $this->browserTimezone);
        $delegate = User::query()->findOrFail((int) $validated['delegateUserId']);

        // Dates, the delegate and the step are checked by the workflow engine (RV-ARC-02).
        try {
            app(WorkflowEngine::class)->delegate(
                $this->submission->workflowInstance,
                new DelegationData(
                    delegator: Auth::user(),
                    delegate: $delegate,
                    expiresAt: $expiresAt,
                    comment: $validated['delegationComment'] ?: null,
                    startsAt: $startsAt,
                    expectedStepId: $this->renderedStepId,
                ),
            );
        } catch (WorkflowException $exception) {
            $this->addError('delegateUserId', $exception->userMessage());

            return;
        }

        Flux::toast('Approval delegated.', variant: 'success');
        $this->redirect(route('submissions.show', $this->submission), navigate: true);
    }

    public function act(string $action): void
    {
        // The workflow engine decides eligibility and explains refusals (ADR 0008).
        $this->authorize('view', $this->submission);
        $this->resetValidation();

        $outcome = collect($this->outcomes)->firstWhere('key', $action);

        if ($outcome === null) {
            Flux::toast(__('That decision is not available on this step.'), variant: 'danger');

            return;
        }

        // Checked here too so the message sits under the comment box; the engine enforces it.
        if ($outcome['requires_comment'] && trim($this->actionComment) === '') {
            $this->addError('actionComment', 'A comment is required for this action.');

            return;
        }

        try {
            $actionType = WorkflowActionType::tryFrom($action) ?? WorkflowActionType::Approve;
            app(WorkflowEngine::class)->processAction(
                $this->submission->workflowInstance,
                new WorkflowActionData(
                    actor: Auth::user(),
                    action: $actionType,
                    comment: trim($this->actionComment) !== '' ? trim($this->actionComment) : null,
                    outcomeKey: $action,
                    expectedStepId: $this->renderedStepId,
                ),
            );
        } catch (WorkflowException $exception) {
            Flux::toast($exception->userMessage(), variant: 'danger');

            return;
        }

        Flux::toast('Recorded.', variant: 'success');
        $this->redirect(route('submissions.show', $this->submission), navigate: true);
    }

    /**
     * Decisions offered on this request's workflow version, with whether each needs a comment.
     *
     * @return list<array{key: string, label: string, behavior: string, requires_comment: bool, custom: bool}>
     */
    #[Computed]
    public function outcomes(): array
    {
        return app(StepOutcomes::class)->forVersion($this->submission->workflowInstance?->templateVersion);
    }
}; ?>

<div class="space-y-6 [&:not(:has(*))]:hidden">
    @if ($this->myDelegation)
        <flux:callout variant="secondary" icon="user-plus" class="mt-6" data-test="my-delegation">
            <flux:callout.heading>{{ __('You delegated this step to :name', ['name' => $this->myDelegation->delegate?->name]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('Until :time. Take it back to decide yourself; the change is recorded in the history.', ['time' => \App\Support\LocalTime::display($this->myDelegation->expires_at, $this->browserTimezone).' ('.\App\Support\LocalTime::label($this->browserTimezone).')']) }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="takeBackDelegation" wire:confirm="{{ __('Take this step back from :name now?', ['name' => $this->myDelegation->delegate?->name]) }}" data-test="take-back-delegation">{{ __('Take back') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @if ($this->selfApprovalBlocked)
        <flux:callout variant="secondary" icon="information-circle" class="mt-6" data-test="self-approval-blocked">
            <flux:callout.heading>{{ __('You cannot approve your own request') }}</flux:callout.heading>
            <flux:callout.text>{{ __('You are on this step, but you submitted this request, so another approver must decide it. You can still comment.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->canAct)
        <flux:card class="sticky bottom-3 z-20 border-blue-200 bg-white/95 shadow-xl backdrop-blur dark:border-blue-900 dark:bg-zinc-900/95">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="sm">{{ __('Your decision') }}</flux:heading>
                    <flux:subheading>{{ __('Shortcuts: [ previous, ] next, A approve, R reject') }}</flux:subheading>
                </div>
                <div class="flex gap-2">
                    <flux:button :href="route('approvals.queue', [$submission, 'previous'])" variant="ghost" aria-label="{{ __('Previous request in my queue') }}" data-test="queue-previous">{{ __('Previous') }}</flux:button>
                    <flux:button :href="route('approvals.queue', [$submission, 'next'])" variant="ghost" aria-label="{{ __('Next request in my queue') }}" data-test="queue-next">{{ __('Next') }}</flux:button>
                </div>
            </div>
            <flux:textarea wire:model="actionComment" :label="__('Comment (required for reject, return, information request, or escalation)')" rows="3" class="mt-3" />
            <flux:error name="actionComment" />

            <div class="mt-4 flex flex-wrap gap-3">
                <flux:modal.trigger name="approve-modal">
                    <flux:button variant="primary" data-test="approve-modal-trigger" data-requires-online>{{ __('Approve') }}</flux:button>
                </flux:modal.trigger>
                <flux:button variant="primary" wire:click="act('approve_with_conditions')" data-test="approve-with-conditions" data-requires-online>{{ __('Approve with conditions') }}</flux:button>
                @foreach ($this->outcomes as $outcome)
                    @if ($outcome['custom'])
                        <flux:button variant="subtle" wire:click="act({{ \Illuminate\Support\Js::from($outcome['key']) }})" data-test="outcome-{{ $outcome['key'] }}">{{ $outcome['label'] }}</flux:button>
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
                    <flux:error name="delegationExpiresAt" />
                    <x-browser-timezone />
                    <flux:text class="text-xs" data-test="delegation-timezone">{{ __('Times are in your time zone (:zone).', ['zone' => \App\Support\LocalTime::label($this->browserTimezone)]) }}</flux:text>
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
</div>
