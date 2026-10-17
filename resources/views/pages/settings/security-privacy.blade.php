<?php

use App\Actions\Security\UpdateSecuritySettingsAction;
use App\Models\SensitiveFieldAccess;
use App\Support\RateLimitProfiles;
use App\Support\SecuritySettings;
use App\Services\Auth\Turnstile;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security and privacy')] class extends Component {
    public bool $watermarkDownloads = false;

    public bool $twoFactorRequired = false;

    public int $rate_limit_login = 5;

    public int $rate_limit_api = 60;

    public int $rate_limit_approval_links = 10;

    public int $rate_limit_exports = 10;

    public int $rate_limit_webhooks = 60;

    public int $rate_limit_integrations = 60;

    public function mount(): void
    {
        $this->authorize('security_settings.manage');
        $this->watermarkDownloads = SecuritySettings::watermarkDownloads();
        $this->twoFactorRequired = SecuritySettings::twoFactorRequired();
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
        $this->authorize('security_settings.manage');

        $validated = $this->validate([
            'watermarkDownloads' => ['boolean'],
            'twoFactorRequired' => ['boolean'],
            'rate_limit_login' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_api' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_approval_links' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_exports' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_webhooks' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_integrations' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            $action->execute([
                'watermark_downloads' => $validated['watermarkDownloads'],
                'two_factor_required' => $validated['twoFactorRequired'],
                ...$validated,
            ], \Illuminate\Support\Facades\Auth::user());
        } catch (\InvalidArgumentException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'twoFactorRequired' => $exception->getMessage(),
            ]);
        }

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
                <flux:heading size="sm">{{ __('Second factor') }}</flux:heading>
                <flux:text class="mt-1">{{ __('When this is on, everyone must confirm an authenticator or add a passkey before they can use ApproveDesk. You must already have one of those on your account to turn it on.') }}</flux:text>
            </div>
            <flux:checkbox wire:model="twoFactorRequired" :label="__('Require a second factor')" data-test="two-factor-required" />
            <flux:error name="twoFactorRequired" />

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

        @php
            $turnstileOn = \App\Models\Setting::get('turnstile.enabled', '0') === '1';
            $turnstileActive = Turnstile::enabled();
        @endphp
        <flux:card class="mt-8" data-test="bot-protection-card">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="sm">{{ __('Bot protection') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Cloudflare Turnstile on sign-in, registration, and password reset. Off until you save a site key and secret and turn it on.') }}</flux:text>
                </div>
                <flux:badge :color="$turnstileActive ? 'lime' : 'zinc'" size="sm">
                    {{ $turnstileActive ? __('Active') : __('Not active') }}
                </flux:badge>
            </div>

            @if (session('status'))
                <flux:text class="mt-3">{{ session('status') }}</flux:text>
            @endif
            @if (session('turnstile_test'))
                <flux:callout class="mt-3" :variant="session('turnstile_test_ok') ? 'success' : 'danger'" data-test="turnstile-test-result">
                    {{ session('turnstile_test') }}
                </flux:callout>
            @endif

            <form method="POST" action="{{ route('settings.turnstile.credentials') }}" class="mt-6 space-y-5">
                @csrf
                <input type="hidden" name="enabled" value="0">
                <flux:checkbox
                    name="enabled"
                    value="1"
                    :checked="old('enabled', $turnstileOn)"
                    :label="__('Require a Turnstile check')"
                    data-test="turnstile-enabled"
                />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        name="site_key"
                        :label="__('Site key')"
                        :value="old('site_key', Turnstile::siteKey())"
                        autocomplete="off"
                        data-test="turnstile-site-key"
                    />
                    <flux:input
                        name="secret"
                        type="password"
                        :label="__('Secret')"
                        :description="Turnstile::hasSecret() ? __('A secret is stored. Leave blank to keep it.') : __('No secret yet.')"
                        autocomplete="off"
                        data-test="turnstile-secret"
                    />
                </div>
                @if ($turnstileOn && ! $turnstileActive)
                    <flux:text>{{ __('Not active until a site key and secret are both saved.') }}</flux:text>
                @endif
                <div class="flex flex-wrap gap-3">
                    <flux:button type="submit" variant="primary" data-test="save-turnstile-secret">{{ __('Save bot protection') }}</flux:button>
                    <flux:button type="submit" name="clear" value="1" variant="danger" data-test="clear-turnstile-secret">{{ __('Clear secret') }}</flux:button>
                </div>
            </form>
            <form method="POST" action="{{ route('settings.turnstile.test') }}" class="mt-3">
                @csrf
                <flux:button type="submit" data-test="test-turnstile">{{ __('Test connection') }}</flux:button>
            </form>
        </flux:card>

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
