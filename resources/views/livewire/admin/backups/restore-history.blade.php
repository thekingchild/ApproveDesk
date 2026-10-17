<?php

use App\Models\BackupRestoreEvent;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * Restore trail on the Backups page (ADR 0006 split).
 */
new class extends Component {
    public function mount(): void
    {
        $this->authorize('backups.view');
    }

    /** @return Collection<int, BackupRestoreEvent> */
    #[Computed]
    public function events(): Collection
    {
        return BackupRestoreEvent::query()->latest('id')->limit(20)->get()->toBase();
    }

    #[On('backups-changed')]
    public function refreshEvents(): void
    {
        unset($this->events);
    }
} ?>

<div class="mt-8" data-test="backup-restore-trail">
    <flux:heading size="sm">{{ __('Restore history') }}</flux:heading>
    <flux:text class="mt-1">{{ __('Each promote is recorded here: who restored which snapshot, and when. Database restores keep this trail on the restored copy.') }}</flux:text>
    <ul class="mt-4 space-y-2 text-sm">
        @forelse ($this->events as $event)
            <li wire:key="restore-event-{{ $event->id }}" data-test="backup-restore-event">
                {{ $event->created_at?->timezone((string) config('app.timezone', 'UTC'))->format('D j M Y, H:i') }}
                —
                {{ $event->kind->value }}
                {{ $event->snapshot_name }}
                {{ __('restored by :name', ['name' => $event->actor_name]) }}
                @if ($event->safety_snapshot_id)
                    · {{ __('safety backup :id', ['id' => $event->safety_snapshot_id]) }}
                @endif
                @if ($event->discarded_workflow_actions)
                    · <span class="font-medium text-amber-700 dark:text-amber-400" data-test="restore-discarded">{{ trans_choice(':count later workflow action removed|:count later workflow actions removed', $event->discarded_workflow_actions) }}</span>
                @endif
            </li>
        @empty
            <li class="text-zinc-500">{{ __('No snapshots have been restored yet.') }}</li>
        @endforelse
    </ul>
</div>
