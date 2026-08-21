<?php

use App\Actions\Settings\UpdateBrandingSettingsAction;
use App\DTOs\BrandingSettingsData;
use App\Models\Setting;
use App\Support\Branding;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Branding settings')] class extends Component {
    use WithFileUploads;

    public string $tagline = '';

    public string $accent_color = '#4f46e5';

    public int $overlay = 40;

    public string $custom_css = '';

    public bool $allow_registration = true;

    public string $meta_title = '';

    public string $meta_description = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $background = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $logo = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $favicon = null;

    public ?string $current_background = null;

    public ?string $current_logo = null;

    public ?string $current_favicon = null;

    public bool $remove_background = false;

    public bool $remove_logo = false;

    public bool $remove_favicon = false;

    public function mount(): void
    {
        $this->authorize('settings.manage');
        $this->tagline = (string) Setting::get('brand.tagline', 'Approvals, clearly tracked.');
        $this->accent_color = (string) Setting::get('brand.accent_color', '#4f46e5');
        $this->overlay = (int) Setting::get('brand.login_background_overlay', '40');
        $this->custom_css = (string) Setting::get('brand.custom_css', '');
        $this->allow_registration = Setting::get('auth.allow_registration', 'true') !== 'false';
        $this->meta_title = (string) Setting::get('brand.meta_title', '');
        $this->meta_description = (string) Setting::get('brand.meta_description', '');
        $this->current_background = Setting::get('brand.login_background');
        $this->current_logo = Setting::get('brand.logo');
        $this->current_favicon = Setting::get('brand.favicon');
    }

    public function updatedBackground(): void
    {
        $this->validate([
            'background' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
    }

    public function updatedLogo(): void
    {
        $this->validate([
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
        ]);
    }

    public function updatedFavicon(): void
    {
        $this->validate([
            'favicon' => ['nullable', 'mimes:ico,png,jpg,jpeg,webp', 'max:512'],
        ]);
    }

    public function save(UpdateBrandingSettingsAction $updateBranding): void
    {
        $this->authorize('settings.manage');

        $this->validate([
            'tagline' => ['required', 'string', 'max:120'],
            'accent_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'overlay' => ['required', 'integer', 'min:0', 'max:90'],
            'custom_css' => ['nullable', 'string', 'max:5000'],
            'meta_title' => ['nullable', 'string', 'max:80'],
            'meta_description' => ['nullable', 'string', 'max:180'],
            'background' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
            'favicon' => ['nullable', 'mimes:ico,png,jpg,jpeg,webp', 'max:512'],
        ]);

        $updateBranding->execute(new BrandingSettingsData(
            tagline: $this->tagline,
            accentColor: $this->accent_color,
            overlay: $this->overlay,
            customCss: $this->custom_css === '' ? null : $this->custom_css,
            allowRegistration: $this->allow_registration,
            metaTitle: $this->meta_title,
            metaDescription: $this->meta_description,
            background: $this->background,
            removeBackground: $this->remove_background,
            logo: $this->logo,
            removeLogo: $this->remove_logo,
            favicon: $this->favicon,
            removeFavicon: $this->remove_favicon,
        ));

        $this->current_background = Setting::get('brand.login_background');
        $this->current_logo = Setting::get('brand.logo');
        $this->current_favicon = Setting::get('brand.favicon');
        $this->background = null;
        $this->logo = null;
        $this->favicon = null;
        $this->remove_background = false;
        $this->remove_logo = false;
        $this->remove_favicon = false;

        Flux::toast('Branding updated.', variant: 'success');
    }

    public function previewStyle(): string
    {
        $url = $this->background?->temporaryUrl()
            ?? ($this->current_background && ! $this->remove_background
                ? Branding::urlFor($this->current_background)
                : null);

        if ($url !== null) {
            return 'background-image: url('.$url.'); background-size: cover; background-position: center;';
        }

        return 'background: linear-gradient(150deg, '.$this->accent_color.' 0%, #17173a 45%, #0a0a0b 100%);';
    }

    public function previewLogoUrl(): ?string
    {
        return $this->logo?->temporaryUrl()
            ?? ($this->current_logo && ! $this->remove_logo
                ? Branding::urlFor($this->current_logo)
                : null);
    }
} ?>

<x-pages::settings.layout :heading="__('Branding')" :subheading="__('Customize the login page and application identity')">
    <div>
        <div class="mb-4 flex items-center gap-1">
            <x-ui.help-link article="organization-settings" />
        </div>
        <div class="flex flex-col gap-6 lg:flex-row">
        <div class="w-full max-w-lg space-y-6">
            <flux:heading size="sm">{{ __('Application identity') }}</flux:heading>

            <flux:input wire:model="meta_title" :label="__('Application name')" :description="__('Shown next to the logo and in the browser tab. Leave blank to use the installed application name.')" maxlength="80" />
            <flux:error name="meta_title" />

            <flux:textarea wire:model="meta_description" :label="__('Meta description')" :description="__('Short description for search engines and link previews.')" rows="3" maxlength="180" />
            <flux:error name="meta_description" />

            <div>
                <flux:input type="file" wire:model="logo" :label="__('Application logo')" accept="image/png,image/jpeg,image/webp" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('PNG, JPG, or WebP. Maximum 1 MB. Replaces the default mark on login and in the signed-in application.') }}</p>
                <flux:error name="logo" />

                @if ($current_logo)
                    <div class="mt-2 flex items-center gap-3">
                        <img src="{{ \App\Support\Branding::urlFor($current_logo) }}" alt="" class="h-10 w-10 rounded-md object-contain ring-1 ring-zinc-200 dark:ring-zinc-700" />
                        <flux:checkbox wire:model="remove_logo" :label="__('Remove current logo')" />
                    </div>
                @endif
            </div>

            <div>
                <flux:input type="file" wire:model="favicon" :label="__('Favicon')" accept=".ico,image/png,image/jpeg,image/webp" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('ICO, PNG, JPG, or WebP. Maximum 512 KB. Shown in the browser tab.') }}</p>
                <flux:error name="favicon" />

                @if ($current_favicon)
                    <div class="mt-2 flex items-center gap-3">
                        <img src="{{ \App\Support\Branding::urlFor($current_favicon) }}" alt="" class="h-8 w-8 rounded-sm object-contain ring-1 ring-zinc-200 dark:ring-zinc-700" />
                        <flux:checkbox wire:model="remove_favicon" :label="__('Remove current favicon')" />
                    </div>
                @endif
            </div>

            <flux:separator />

            <flux:heading size="sm">{{ __('Login page') }}</flux:heading>

            <flux:input wire:model="tagline" :label="__('Tagline')" placeholder="Approvals, clearly tracked." />

            <div class="flex items-end gap-4">
                <div class="w-16">
                    <flux:input wire:model="accent_color" type="color" :label="__('Accent color')" class="h-10 p-1" />
                </div>
                <flux:input wire:model="accent_color" :label="__('Hex value')" class="flex-1" />
            </div>

            <div>
                <flux:input wire:model.live="overlay" type="range" min="0" max="90" :label="__('Background dark overlay (%)')" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Only applies when a background image is set.') }}</p>
            </div>

            <div>
                <flux:input type="file" wire:model="background" :label="__('Login background image')" accept="image/*" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('JPG, PNG, or WebP. Maximum 2 MB. Shown on the login page at every screen width.') }}</p>
                <flux:error name="background" />

                @if ($current_background)
                    <div class="mt-2 flex items-center gap-3">
                        <flux:checkbox wire:model="remove_background" :label="__('Remove current background')" />
                    </div>
                @endif
            </div>

            <flux:textarea wire:model="custom_css" :label="__('Custom CSS (optional)')" rows="4" placeholder="/* e.g. .brand-mark { border-radius: 0; } */" />
            <flux:error name="custom_css" />

            <flux:checkbox wire:model="allow_registration" :label="__('Allow self-registration')" />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" wire:click="save" data-test="save-branding">{{ __('Save branding') }}</flux:button>
            </div>
        </div>

        <flux:card class="w-full max-w-sm self-start">
            <flux:heading size="sm">{{ __('Live preview — login panel') }}</flux:heading>
            <div class="relative mt-3 h-64 overflow-hidden rounded-lg" style="{{ $this->previewStyle() }}">
                @if ($this->previewStyle() !== '' && str_contains($this->previewStyle(), 'background-image'))
                    <div class="absolute inset-0" style="background-color: rgba(0,0,0, {{ $overlay / 100 }})"></div>
                @endif
                <div class="relative z-10 flex h-full flex-col justify-between p-4 text-white">
                    <div class="flex items-center gap-2 text-sm font-medium">
                        @if ($logo = $this->previewLogoUrl())
                            <img src="{{ $logo }}" alt="" class="h-7 w-7 rounded-md object-contain" />
                        @else
                            <span class="flex h-7 w-7 items-center justify-center rounded-md" style="background: {{ $accent_color }}">AD</span>
                        @endif
                        {{ $meta_title !== '' ? $meta_title : config('app.name') }}
                    </div>
                    <flux:heading size="lg">{{ $tagline ?: 'Approvals, clearly tracked.' }}</flux:heading>
                </div>
            </div>
        </flux:card>
        </div>
    </div>
</x-pages::settings.layout>
