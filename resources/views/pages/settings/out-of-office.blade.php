<?php

use App\Actions\Collaboration\DeleteOutOfOfficeAction;
use App\Actions\Collaboration\SaveOutOfOfficeAction;
use App\Exceptions\CollaborationException;
use App\Models\OutOfOffice;
use App\Models\User;
use App\Services\Workflow\AssigneeDirectory;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Out of office')] class extends Component {
    /** Set from the browser (<x-browser-timezone />) so datetime-local values are read in the viewer's timezone. */
    public string $browserTimezone = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $fallbackQuery = '';

    public string $fallbackUserId = '';

    public string $message = '';

    public function save(SaveOutOfOfficeAction $save): void
    {
        $validated = $this->validate([
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
            'fallbackUserId' => ['nullable', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $save->execute(
                Auth::user(),
                \App\Support\LocalTime::parse($validated['startsAt'], $this->browserTimezone),
                \App\Support\LocalTime::parse($validated['endsAt'], $this->browserTimezone),
                isset($validated['fallbackUserId']) && $validated['fallbackUserId'] !== ''
                    ? (int) $validated['fallbackUserId']
                    : null,
                $validated['message'] ?? null,
            );
        } catch (CollaborationException $exception) {
            $this->addError('startsAt', $exception->getMessage());

            return;
        }

        $this->reset('startsAt', 'endsAt', 'fallbackQuery', 'fallbackUserId', 'message');
        unset($this->schedules, $this->fallbackResults, $this->selectedFallback);
        Flux::toast('Out-of-office schedule saved.', variant: 'success');
    }

    public function remove(int $scheduleId, DeleteOutOfOfficeAction $delete): void
    {
        try {
            $delete->execute(Auth::user(), $scheduleId);
        } catch (CollaborationException $exception) {
            Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        unset($this->schedules);
        Flux::toast('Out-of-office schedule removed.', variant: 'success');
    }

    public function chooseFallback(int $userId): void
    {
        $this->fallbackUserId = (string) $userId;
        $this->fallbackQuery = '';
        unset($this->fallbackResults, $this->selectedFallback);
    }

    public function clearFallback(?int $userId = null): void
    {
        $this->fallbackUserId = '';
        $this->fallbackQuery = '';
        unset($this->fallbackResults, $this->selectedFallback);
    }

    #[Computed]
    public function schedules(): \Illuminate\Database\Eloquent\Collection
    {
        return OutOfOffice::query()
            ->where('user_id', Auth::id())
            ->with('fallback')
            ->orderBy('starts_at')
            ->get();
    }

    #[Computed]
    public function fallbackResults(): \Illuminate\Support\Collection
    {
        return app(AssigneeDirectory::class)->search('user', $this->fallbackQuery, [(int) Auth::id()]);
    }

    #[Computed]
    public function selectedFallback(): ?User
    {
        if ($this->fallbackUserId === '') {
            return null;
        }

        return User::query()->find((int) $this->fallbackUserId);
    }
}; ?>

<x-pages::settings.layout :heading="__('Out of office')" :subheading="__('Pause your approvals and name a fallback while you are away.')">
    <div class="mb-4 flex items-center gap-1">
        <x-ui.help-link article="collaboration" />
    </div>

    <form wire:submit="save" class="space-y-4" data-test="ooo-form">
        <flux:input wire:model="startsAt" type="datetime-local" :label="__('Starts')" required />
        <flux:error name="startsAt" />
        <flux:input wire:model="endsAt" type="datetime-local" :label="__('Ends')" required />
        <x-browser-timezone />
        <flux:text class="text-xs">{{ __('Times are in your time zone (:zone).', ['zone' => \App\Support\LocalTime::label($this->browserTimezone)]) }}</flux:text>
        <flux:error name="endsAt" />
        <div>
            <flux:heading size="sm">{{ __('Fallback approver') }}</flux:heading>
            <flux:subheading>{{ __('Optional. They receive your current-step work while this schedule is active.') }}</flux:subheading>
            <div class="mt-2">
                <x-ui.assignee-picker
                    type="user"
                    query="fallbackQuery"
                    :selected="collect(array_filter([$this->selectedFallback]))"
                    :results="$this->fallbackResults"
                    add="chooseFallback"
                    remove="clearFallback"
                    placeholder="Type a name to find a fallback…"
                />
            </div>
        </div>
        <flux:textarea wire:model="message" :label="__('Note (optional)')" rows="2" />
        <flux:button type="submit" variant="primary" data-test="save-ooo">{{ __('Save schedule') }}</flux:button>
    </form>

    <ul class="mt-8 space-y-3" data-test="ooo-list">
        @forelse ($this->schedules as $schedule)
            <li class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                <p class="font-medium">
                    {{ \App\Support\LocalTime::display($schedule->starts_at, $this->browserTimezone) }}
                    –
                    {{ \App\Support\LocalTime::display($schedule->ends_at, $this->browserTimezone) }}
                </p>
                <p class="text-zinc-500">
                    {{ $schedule->fallback?->name ? __('Fallback: :name', ['name' => $schedule->fallback->name]) : __('No fallback') }}
                </p>
                @if ($schedule->message)
                    <p class="mt-1">{{ $schedule->message }}</p>
                @endif
                <flux:button size="xs" variant="ghost" wire:click="remove({{ $schedule->id }})" class="mt-2">{{ __('Remove') }}</flux:button>
            </li>
        @empty
            <li class="text-sm text-zinc-500">{{ __('No out-of-office schedules yet.') }}</li>
        @endforelse
    </ul>
</x-pages::settings.layout>
