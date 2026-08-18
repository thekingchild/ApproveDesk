<?php

use App\Enums\WorkflowStatus;
use App\Models\WorkflowInstance;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Approver inbox: what needs my action now, what I have acted on but is
 * still moving, and what finished recently.
 */
new #[Title('Approvals')] class extends Component {
    /** @return array<int, array{instance: WorkflowInstance, needs_me: bool}> */
    #[Computed]
    public function inbox(): array
    {
        $user = Auth::user();

        // Instances I have been involved in (acted on)…
        $involved = WorkflowInstance::query()
            ->with(['submission.submitter', 'currentStep', 'actions', 'delegations'])
            ->whereHas('actions', fn ($query) => $query->where('actor_id', $user->id))
            ->get();

        // …plus active instances whose current step is assigned to me.
        $assigned = WorkflowInstance::query()
            ->where('status', WorkflowStatus::Active)
            ->with(['submission.submitter', 'currentStep', 'actions', 'delegations'])
            ->get()
            ->filter(function ($instance) use ($user): bool {
                $activeDelegations = $instance->delegations->filter(fn ($delegation) => $delegation->step_id === $instance->current_step_id
                    && $delegation->starts_at <= now()
                    && $delegation->expires_at > now());
                $delegatedToUser = $activeDelegations->contains('delegate_id', $user->id);
                $delegatedByUser = $activeDelegations->contains('delegator_id', $user->id);

                return $delegatedToUser
                    || ($instance->currentStep?->assignedUsers()->contains($user) && ! $delegatedByUser);
            });

        return $assigned->concat($involved)
            ->unique('id')
            ->map(function ($instance) use ($user): array {
                $activeDelegations = $instance->delegations->filter(fn ($delegation) => $delegation->step_id === $instance->current_step_id
                    && $delegation->starts_at <= now()
                    && $delegation->expires_at > now());
                $delegatedToUser = $activeDelegations->contains('delegate_id', $user->id);
                $delegatedByUser = $activeDelegations->contains('delegator_id', $user->id);
                $isEligible = $delegatedToUser
                    || ($instance->currentStep?->assignedUsers()->contains($user) && ! $delegatedByUser);

                return [
                    'instance' => $instance,
                    'needs_me' => $instance->status === WorkflowStatus::Active
                        && $isEligible
                        && ! $instance->actions
                            ->where('step_id', $instance->current_step_id)
                            ->where('actor_id', $user->id)
                            ->whereIn('action', ['approve', 'reject', 'escalate'])
                            ->isNotEmpty(),
                ];
            })
            ->sortByDesc(fn ($item) => $item['instance']->submission->updated_at)
            ->values()
            ->all();
    }
} ?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Approvals')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <x-ui.page-header
        :title="__('Approvals')"
        :description="__('Submissions routed to you for review or decision.')"
    />

    <div class="grid gap-6 lg:grid-cols-3">
        @php
            $to_action = [];
            $pending_others = [];
            $completed = [];
        @endphp
        @foreach ($this->inbox as $item)
            @if ($item['needs_me'])
                @php(array_push($to_action, $item['instance']))
            @elseif (in_array($item['instance']->status, [WorkflowStatus::Active, WorkflowStatus::OnHold], true))
                @php(array_push($pending_others, $item['instance']))
            @else
                @php(array_push($completed, $item['instance']))
            @endif
        @endforeach

        @foreach ([__('To Action') => $to_action, __('Pending Others') => $pending_others, __('Completed') => $completed] as $heading => $instances)
            <flux:card class="min-h-40">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">{{ $heading }}</flux:heading>
                    <flux:badge color="zinc">{{ count($instances) }}</flux:badge>
                </div>

                <ul class="mt-4 space-y-3">
                    @forelse ($instances as $instance)
                        @php($submission = $instance->submission)
                        <li class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <flux:link :href="route('submissions.show', $submission)" wire:navigate class="text-sm font-medium">
                                {{ Str::limit($submission->title, 48) }}
                            </flux:link>
                            <p class="mt-1 font-mono text-xs text-zinc-400">{{ $submission->reference_number }}</p>
                            <div class="mt-2 flex items-center gap-2">
                                <flux:badge color="{{ ['active' => 'blue', 'on_hold' => 'amber', 'completed' => 'lime', 'rejected' => 'red', 'cancelled' => 'zinc'][$instance->status->value] ?? 'zinc' }}">
                                    {{ str_replace('_', ' ', $instance->status->value) }}
                                </flux:badge>
                                @if ($instance->isOverdue())
                                    <flux:badge color="red">{{ __('Overdue') }}</flux:badge>
                                @endif
                                @if ($submission->priority->value !== 'normal')
                                    <flux:badge color="{{ ['urgent' => 'red', 'high' => 'amber', 'low' => 'zinc'][$submission->priority->value] ?? 'zinc' }}">{{ $submission->priority->value }}</flux:badge>
                                @endif
                            </div>
                            <p class="mt-2 text-xs text-zinc-500">{{ $submission->submitter->name }} · {{ $submission->updated_at?->diffForHumans() }}</p>
                        </li>
                    @empty
                        <li>
                            <x-ui.empty-state
                                :title="__('No approvals in this view')"
                                :description="__('Items will appear here as workflows move forward.')"
                                icon="inbox"
                                class="py-6"
                            />
                        </li>
                    @endforelse
                </ul>
            </flux:card>
        @endforeach
    </div>
</div>
