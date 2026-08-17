<?php

use App\Actions\Settings\UpdateApprovalSettingsAction;
use App\Support\EmailApprovalLinks;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Approval settings')] class extends Component {
    public int $emailLinkExpirationHours = EmailApprovalLinks::DEFAULT_EXPIRATION_HOURS;

    public function mount(): void
    {
        $this->authorize('settings.manage');
        $this->emailLinkExpirationHours = EmailApprovalLinks::expirationHours();
    }

    public function save(UpdateApprovalSettingsAction $updateSettings): void
    {
        $this->authorize('settings.manage');
        $validated = $this->validate([
            'emailLinkExpirationHours' => ['required', 'integer', 'min:1', 'max:'.EmailApprovalLinks::MAX_EXPIRATION_HOURS],
        ]);

        $updateSettings->execute($validated['emailLinkExpirationHours']);
        Flux::toast('Approval settings updated.', variant: 'success');
    }
} ?>

<x-pages::settings.layout :heading="__('Approval settings')" :subheading="__('Configure secure approval actions sent by email.')">
    <form wire:submit="save" class="space-y-6">
        <flux:input
            wire:model="emailLinkExpirationHours"
            type="number"
            min="1"
            :max="\App\Support\EmailApprovalLinks::MAX_EXPIRATION_HOURS"
            :label="__('Email approval link lifetime (hours)')"
            :description="__('Signed links stop working after this period. Choose between 1 and 720 hours.')"
            required
        />

        <flux:button type="submit" variant="primary">{{ __('Save approval settings') }}</flux:button>
    </form>
</x-pages::settings.layout>
