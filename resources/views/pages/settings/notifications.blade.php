<?php

use App\Enums\NotificationFrequency;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification settings')] class extends Component {
    public string $frequency = NotificationFrequency::Immediate->value;

    public function mount(): void
    {
        $this->frequency = Auth::user()->notification_frequency->value;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'frequency' => ['required', Rule::enum(NotificationFrequency::class)],
        ]);

        Auth::user()->update([
            'notification_frequency' => NotificationFrequency::from($validated['frequency']),
            'last_notification_digest_at' => null,
        ]);

        Flux::toast('Notification preferences saved.', variant: 'success');
    }
}; ?>

<x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Choose when approval emails reach your inbox.')">
    <form wire:submit="save" class="space-y-6">
        <flux:radio.group wire:model="frequency" :label="__('Email delivery')">
            <flux:radio value="immediate" :label="__('Immediately')" :description="__('Send each approval update as it happens.')" />
            <flux:radio value="daily" :label="__('Daily digest')" :description="__('Combine updates into one daily email.')" />
            <flux:radio value="weekly" :label="__('Weekly digest')" :description="__('Combine updates into one email every seven days.')" />
            <flux:radio value="in_app_only" :label="__('In-app only')" :description="__('Keep updates in ApproveDesk without workflow emails.')" />
        </flux:radio.group>
        <flux:error name="frequency" />
        <flux:button type="submit" variant="primary">{{ __('Save preferences') }}</flux:button>
    </form>
</x-pages::settings.layout>
