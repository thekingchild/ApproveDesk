<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Privacy')] class extends Component {
    public function mount(): void
    {
        //
    }

    public function canDeleteAccount(): bool
    {
        return ! Auth::user()->hasPreservedAuditEvidence();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Privacy')" :subheading="__('Download a copy of your data. Closing an account that has request history is done by an administrator.')">
        <div class="space-y-6">
            <div>
                <flux:heading size="sm">{{ __('Download my data') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('The file includes your profile, requests you submitted, comments, followers, out-of-office periods, sign-in history, and the decisions recorded in your name. Passwords, recovery codes, API tokens, and connector secrets are not included.') }}
                </flux:text>
                <div class="mt-4">
                    <flux:button :href="route('privacy.export')" icon="arrow-down-tray" data-test="export-my-data">
                        {{ __('Download my data') }}
                    </flux:button>
                </div>
            </div>

            @if ($this->canDeleteAccount())
                <livewire:pages::settings.delete-user-form />
            @else
                <div>
                    <flux:heading size="sm">{{ __('Close this account') }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('This account has request or decision history. An administrator can anonymize it so your name and email are removed while the history stays on those requests.') }}
                    </flux:text>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
