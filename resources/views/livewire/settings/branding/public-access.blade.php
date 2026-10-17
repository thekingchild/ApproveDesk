<?php

use App\Actions\Settings\UpdateRegistrationSettingsAction;
use App\DTOs\RegistrationSettingsData;
use App\Rules\DomainNameList;
use App\Support\Branding;
use App\Support\RegistrationSettings;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Component;

/*
 * Public landing page and self-registration on the Branding page (ADR 0006
 * split, RV-ARC-10).
 */
new class extends Component {
    public bool $landing_page_enabled = false;

    public bool $allow_registration = true;

    public string $registration_mode = RegistrationSettings::MODE_OTP;

    public string $allowed_registration_domains = '';

    public function mount(): void
    {
        $this->authorize('branding.manage');
        $this->landing_page_enabled = Branding::landingPageEnabled();
        $this->allow_registration = Branding::registrationOpen();
        $this->registration_mode = RegistrationSettings::mode();
        $this->allowed_registration_domains = implode("\n", RegistrationSettings::allowedDomains());
    }

    public function save(UpdateRegistrationSettingsAction $update): void
    {
        $this->authorize('branding.manage');

        $this->validate([
            'landing_page_enabled' => ['boolean'],
            'allow_registration' => ['boolean'],
            'registration_mode' => ['required', Rule::in([RegistrationSettings::MODE_IMMEDIATE, RegistrationSettings::MODE_OTP])],
            'allowed_registration_domains' => ['nullable', 'string', 'max:5000', new DomainNameList],
        ]);

        $update->execute(new RegistrationSettingsData(
            allowRegistration: $this->allow_registration,
            landingPageEnabled: $this->landing_page_enabled,
            registrationMode: $this->registration_mode,
            allowedRegistrationDomains: $this->allowed_registration_domains,
        ));

        $this->allowed_registration_domains = implode("\n", RegistrationSettings::allowedDomains());
        Flux::toast(__('Public access saved.'), variant: 'success');
    }
} ?>

<flux:card data-test="public-access-card">
    <div class="flex items-center justify-between">
        <flux:heading size="md">{{ __('Public access') }}</flux:heading>
        <p class="text-xs text-zinc-400">{{ __('The first page guests see, and who may sign up') }}</p>
    </div>

    <form wire:submit="save" class="mt-4 space-y-4">
        <div class="space-y-1.5 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:checkbox wire:model="landing_page_enabled" :label="__('Show public landing page')" />
            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('When off, the site address opens Login. Turn it on to show the public website.') }}</p>
        </div>

        <flux:checkbox wire:model.live="allow_registration" :label="__('Allow self-registration')" />

        @if ($allow_registration)
            <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="registration-controls">
                <flux:select wire:model="registration_mode" :label="__('After someone registers')">
                    <flux:select.option value="otp">{{ __('Require email confirmation (recommended)') }}</flux:select.option>
                    <flux:select.option value="immediate">{{ __('Grant immediate access') }}</flux:select.option>
                </flux:select>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('With email confirmation, the account cannot sign in until the person enters the 6-digit code sent to their address. Codes expire after 10 minutes.') }}
                </p>

                <flux:textarea
                    wire:model="allowed_registration_domains"
                    :label="__('Registration domains')"
                    rows="3"
                    placeholder="{{ __('example.com') }}&#10;{{ __('mail.example.com') }}"
                />
                <flux:error name="allowed_registration_domains" />
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('One domain per line. Only people with an email at these domains may register — useful when only organization addresses should sign up. Leave empty to allow any email provider.') }}
                </p>
            </div>
        @endif

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" data-test="save-public-access">{{ __('Save public access') }}</flux:button>
        </div>
    </form>
</flux:card>
