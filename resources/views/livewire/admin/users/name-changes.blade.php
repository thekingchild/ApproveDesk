<?php

use App\Actions\Users\DecideDisplayNameChangeAction;
use App\Enums\DisplayNameChangeStatus;
use App\Models\DisplayNameChange;
use App\Models\User;
use App\Support\UserInputRules;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Display-name review on the Users page (ADR 0006 split, RV-ARC-08).
 */
new class extends Component {
    #[Locked]
    public ?int $rejectingId = null;

    public string $rejectionReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /** Pending requests for people this viewer may see; the hidden owner account stays hidden. */
    #[Computed]
    public function changes(): Collection
    {
        return DisplayNameChange::query()
            ->where('status', DisplayNameChangeStatus::Pending)
            ->whereHas('user', fn ($user) => $user->visibleTo(Auth::user()))
            ->with('user')
            ->latest()
            ->get();
    }

    public function approve(int $changeId, DecideDisplayNameChangeAction $decide): void
    {
        $this->authorize('viewAny', User::class);
        $decide->approve(Auth::user(), $this->find($changeId));
        unset($this->changes);
        $this->dispatch('users-changed');
        Flux::toast(__('Display name approved.'), variant: 'success');
    }

    public function startReject(int $changeId): void
    {
        $this->authorize('viewAny', User::class);
        $this->rejectingId = $this->find($changeId)->id;
        $this->rejectionReason = '';
        $this->resetValidation();
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectionReason = '';
    }

    public function reject(DecideDisplayNameChangeAction $decide): void
    {
        $this->authorize('viewAny', User::class);
        abort_if($this->rejectingId === null, 404);
        $this->validate(['rejectionReason' => UserInputRules::rejectionReason()]);

        $decide->reject(Auth::user(), $this->find($this->rejectingId), trim($this->rejectionReason));
        $this->cancelReject();
        unset($this->changes);
        Flux::toast(__('Display name rejected.'), variant: 'success');
    }

    private function find(int $changeId): DisplayNameChange
    {
        return DisplayNameChange::query()
            ->whereHas('user', fn ($user) => $user->visibleTo(Auth::user()))
            ->findOrFail($changeId);
    }
} ?>

<div>
    @if ($this->changes->isNotEmpty())
        <flux:card class="space-y-4" data-test="display-name-review">
            <div>
                <flux:heading size="sm">{{ __('Display-name review') }}</flux:heading>
                <flux:text>{{ __('Names remain unchanged until a different authorized reviewer approves them.') }}</flux:text>
            </div>
            @foreach ($this->changes as $change)
                <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700" wire:key="name-change-{{ $change->id }}">
                    <div class="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                        <div><div class="font-medium">{{ $change->user->name }}</div><div class="text-xs text-zinc-500">{{ $change->user->email }}</div></div>
                        <div><div class="text-xs text-zinc-500">{{ __('Requested name') }}</div><div class="font-medium">{{ $change->proposed_name }}</div></div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if (! Auth::user()->is($change->user) && Auth::user()->can('update', $change->user))
                                <flux:button size="xs" variant="primary" wire:click="approve({{ $change->id }})">{{ __('Approve') }}</flux:button>
                                <flux:button size="xs" variant="danger" wire:click="startReject({{ $change->id }})" data-test="reject-name-{{ $change->id }}">{{ __('Reject') }}</flux:button>
                            @else
                                <flux:badge color="amber">{{ __('Another authorized reviewer must decide') }}</flux:badge>
                            @endif
                        </div>
                    </div>
                    @if ($rejectingId === $change->id)
                        <form wire:submit="reject" class="flex flex-wrap items-end gap-2" data-test="reject-name-form">
                            <div class="min-w-64 flex-1">
                                <flux:input wire:model="rejectionReason" :label="__('Reason for rejection')" maxlength="1000" required x-autofocus />
                                <flux:error name="rejectionReason" />
                            </div>
                            <flux:button type="submit" size="sm" variant="danger">{{ __('Reject name') }}</flux:button>
                            <flux:button type="button" size="sm" variant="ghost" wire:click="cancelReject">{{ __('Cancel') }}</flux:button>
                        </form>
                    @endif
                </div>
            @endforeach
        </flux:card>
    @endif
</div>
