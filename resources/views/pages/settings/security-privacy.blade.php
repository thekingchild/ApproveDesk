<?php

use App\Actions\Security\UpdateSecuritySettingsAction;
use App\Models\SensitiveFieldAccess;
use App\Support\RateLimitProfiles;
use App\Support\SecuritySettings;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security and privacy')] class extends Component {
    public bool $watermarkDownloads = false;

    public int $rate_limit_login = 5;

    public int $rate_limit_api = 60;

    public int $rate_limit_approval_links = 10;

    public int $rate_limit_exports = 10;

    public int $rate_limit_webhooks = 60;

    public int $rate_limit_integrations = 60;

    public function mount(): void
    {
        $this->authorize('settings.manage');
        $this->watermarkDownloads = SecuritySettings::watermarkDownloads();
        $profiles = RateLimitProfiles::all();
        $this->rate_limit_login = $profiles['login'];
        $this->rate_limit_api = $profiles['api'];
        $this->rate_limit_approval_links = $profiles['approval_links'];
        $this->rate_limit_exports = $profiles['exports'];
        $this->rate_limit_webhooks = $profiles['webhooks'];
        $this->rate_limit_integrations = $profiles['integrations'];
    }

    public function save(UpdateSecuritySettingsAction $action): void
    {
        $this->authorize('settings.manage');

        $validated = $this->validate([
            'watermarkDownloads' => ['boolean'],
            'rate_limit_login' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_api' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_approval_links' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_exports' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_webhooks' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_integrations' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $action->execute([
            'watermark_downloads' => $validated['watermarkDownloads'],
            ...$validated,
        ]);

        Flux::toast(variant: 'success', text: __('Security and privacy settings saved.'));
    }

    #[Computed]
    public function recentAccesses(): \Illuminate\Database\Eloquent\Collection
    {
        return SensitiveFieldAccess::query()
            ->with(['user:id,name,email', 'submission:id,reference_number,title', 'field:id,label'])
            ->latest('id')
            ->limit(25)
            ->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Security & privacy')" :subheading="__('Rate limits, download watermarks, and who opened sensitive fields.')" :wide="true">
        <form wire:submit="save" class="space-y-8">
            <div class="flex items-center gap-1">
                <flux:heading size="sm">{{ __('Downloads') }}</flux:heading>
                <x-ui.help-link article="security-and-privacy" />
            </div>
            <flux:checkbox wire:model="watermarkDownloads" :label="__('Stamp file downloads with who downloaded them')" data-test="watermark-downloads" />
            <flux:text>{{ __('Images get a visible stamp. Other files download as a zip with a notice. Previews are not stamped.') }}</flux:text>

            <div>
                <flux:heading size="sm">{{ __('Rate limits') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Attempts allowed per minute. Lower numbers stop guessing and bulk downloads sooner.') }}</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="rate_limit_login" type="number" min="1" max="1000" :label="__('Sign-in')" data-test="rate-limit-login" />
                <flux:input wire:model="rate_limit_api" type="number" min="1" max="1000" :label="__('API')" />
                <flux:input wire:model="rate_limit_approval_links" type="number" min="1" max="1000" :label="__('Approval links')" />
                <flux:input wire:model="rate_limit_exports" type="number" min="1" max="1000" :label="__('Exports')" />
                <flux:input wire:model="rate_limit_webhooks" type="number" min="1" max="1000" :label="__('Incoming webhooks')" />
                <flux:input wire:model="rate_limit_integrations" type="number" min="1" max="1000" :label="__('Integrations')" />
            </div>

            <flux:button type="submit" variant="primary" data-test="save-security-privacy">{{ __('Save') }}</flux:button>
        </form>

        <div class="mt-10">
            <flux:heading size="sm">{{ __('Sensitive field access') }}</flux:heading>
            <flux:text class="mt-1">{{ __('When someone other than the requester opens a sensitive field, ApproveDesk records it here.') }}</flux:text>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-zinc-500">
                            <th class="py-2 pr-3">{{ __('When') }}</th>
                            <th class="py-2 pr-3">{{ __('Who') }}</th>
                            <th class="py-2 pr-3">{{ __('Request') }}</th>
                            <th class="py-2 pr-3">{{ __('Field') }}</th>
                            <th class="py-2">{{ __('Where') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->recentAccesses as $access)
                            <tr class="border-t border-zinc-200 dark:border-zinc-800">
                                <td class="py-2 pr-3">{{ $access->created_at?->diffForHumans() }}</td>
                                <td class="py-2 pr-3">{{ $access->user?->name }}</td>
                                <td class="py-2 pr-3">{{ $access->submission?->reference_number }}</td>
                                <td class="py-2 pr-3">{{ $access->field?->label }}</td>
                                <td class="py-2">{{ $access->context }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-4 text-zinc-500">{{ __('No sensitive-field access yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
