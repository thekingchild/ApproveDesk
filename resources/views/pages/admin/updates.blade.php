<?php

use App\Actions\Updates\ApplyDatabaseUpdatesAction;
use App\Models\Setting;
use App\Services\Operations\MigrationPreflight;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Updates')] class extends Component
{
    public string $confirmation = '';

    public ?string $applyResult = null;

    public ?string $checkResult = null;

    public function mount(): void
    {
        $this->authorize('updates.view');
    }

    #[Computed]
    public function version(): string
    {
        return (string) config('app.version', '2.7.3');
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function pending(): array
    {
        return app(MigrationPreflight::class)->pendingNames();
    }

    /**
     * @return array<string, list<string>>
     */
    #[Computed]
    public function preflight(): array
    {
        return app(MigrationPreflight::class)->inspectPending();
    }

    public function lastAppliedAt(): ?string
    {
        return Setting::get('updates.last_applied_at');
    }

    public function checkUpdates(): void
    {
        $this->authorize('updates.view');
        unset($this->pending, $this->preflight);

        $flagged = $this->preflight;

        $this->checkResult = $flagged === []
            ? (count($this->pending) === 0
                ? __('No pending database updates.')
                : __('Pending updates look safe to apply.'))
            : __('Pending updates include unsafe changes and will not apply.');
    }

    public function apply(ApplyDatabaseUpdatesAction $apply): void
    {
        $this->authorize('updates.apply');

        try {
            $this->applyResult = $apply->execute(Auth::user(), $this->confirmation);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['confirmation' => $exception->getMessage()]);
        }

        $this->confirmation = '';
        unset($this->pending, $this->preflight);
        Flux::toast(__('Database updates finished.'), variant: 'success');
    }
};
?>

<div>
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Updates')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <x-ui.page-header :title="__('Updates')" :description="__('Apply waiting database changes after a new copy of ApproveDesk is deployed. This is not the first-time setup wizard.')" help-article="updates" />

    <div class="mt-8 grid gap-8 lg:grid-cols-2">
        <flux:card>
            <flux:heading size="sm">{{ __('This copy') }}</flux:heading>
            <p class="mt-2 text-sm" data-test="updates-version">{{ __('Version :version', ['version' => $this->version]) }}</p>
            <p class="mt-1 text-sm text-zinc-500" data-test="updates-last-applied">
                @if ($this->lastAppliedAt())
                    {{ __('Last applied :time', ['time' => \Illuminate\Support\Carbon::parse($this->lastAppliedAt())->diffForHumans()]) }}
                @else
                    {{ __('No in-app apply has been recorded yet.') }}
                @endif
            </p>
        </flux:card>

        <flux:card>
            <flux:heading size="sm">{{ __('Pending database updates') }}</flux:heading>
            <ul class="mt-3 space-y-1 text-sm" data-test="updates-pending">
                @forelse ($this->pending as $name)
                    <li>{{ $name }}</li>
                @empty
                    <li class="text-zinc-500">{{ __('None. This copy is up to date.') }}</li>
                @endforelse
            </ul>
            <div class="mt-4">
                <flux:button wire:click="checkUpdates" data-test="updates-check">{{ __('Check updates') }}</flux:button>
            </div>
            @if ($checkResult)
                <p class="mt-3 text-sm" data-test="updates-check-result">{{ $checkResult }}</p>
            @endif
        </flux:card>

        <flux:card class="lg:col-span-2">
            <flux:heading size="sm">{{ __('Apply database updates') }}</flux:heading>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Take a host backup first. This applies waiting database changes only. It does not replace application files and it cannot undo a change.') }}</p>
            <div class="mt-4 max-w-sm space-y-3">
                <flux:input wire:model="confirmation" :label="__('Type APPLY to confirm')" data-test="updates-confirm" />
                <flux:error name="confirmation" />
                <flux:button variant="primary" wire:click="apply" data-test="updates-apply">{{ __('Apply database updates') }}</flux:button>
            </div>
            @if ($applyResult)
                <p class="mt-3 text-sm" data-test="updates-apply-result">{{ $applyResult }}</p>
            @endif
        </flux:card>
    </div>
</div>
