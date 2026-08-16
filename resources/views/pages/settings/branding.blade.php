<?php

use App\Models\Setting;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
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

    /** @var \Livewire\TemporaryUploadedFile|null */
    public $background = null;

    public ?string $current_background = null;

    public bool $remove_background = false;

    public function mount(): void
    {
        $this->tagline = (string) Setting::get('brand.tagline', 'Approvals, clearly tracked.');
        $this->accent_color = (string) Setting::get('brand.accent_color', '#4f46e5');
        $this->overlay = (int) Setting::get('brand.login_background_overlay', '40');
        $this->custom_css = (string) Setting::get('brand.custom_css', '');
        $this->allow_registration = Setting::get('auth.allow_registration', 'true') !== 'false';
        $this->current_background = Setting::get('brand.login_background');
    }

    public function updatedBackground(): void
    {
        $this->validate([
            'background' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
    }

    public function save(): void
    {
        $this->validate([
            'tagline' => ['required', 'string', 'max:120'],
            'accent_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'overlay' => ['required', 'integer', 'min:0', 'max:90'],
            'custom_css' => ['nullable', 'string', 'max:5000'],
            'background' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        Setting::put('brand.tagline', $this->tagline);
        Setting::put('brand.accent_color', $this->accent_color);
        Setting::put('brand.login_background_overlay', (string) $this->overlay);
        Setting::put('brand.custom_css', $this->custom_css === '' ? null : $this->custom_css);
        Setting::put('auth.allow_registration', $this->allow_registration ? 'true' : 'false');

        if ($this->remove_background && $this->current_background) {
            Storage::disk('public')->delete($this->current_background);
            Setting::put('brand.login_background', null);
            $this->current_background = null;
            $this->remove_background = false;
        }

        if ($this->background) {
            if ($this->current_background) {
                Storage::disk('public')->delete($this->current_background);
            }

            $this->current_background = $this->background->store('branding', 'public');
            Setting::put('brand.login_background', $this->current_background);
            $this->background = null;
        }

        Flux::toast('Branding updated.', variant: 'success');
    }

    public function previewStyle(): string
    {
        $url = $this->background?->temporaryUrl()
            ?? ($this->current_background && ! $this->remove_background
                ? Storage::disk('public')->url($this->current_background)
                : null);

        if ($url !== null) {
            return 'background-image: url('.$url.'); background-size: cover; background-position: center;';
        }

        return 'background: linear-gradient(150deg, '.$this->accent_color.' 0%, #17173a 45%, #0a0a0b 100%);';
    }
} ?>

<x-pages::settings.layout :heading="__('Branding')" :subheading="__('Customize the login page and application branding')">
    <div>
        <div class="flex flex-col gap-6 lg:flex-row">
        <div class="w-full max-w-lg space-y-6">
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
                        <span class="flex h-7 w-7 items-center justify-center rounded-md" style="background: {{ $accent_color }}">AD</span>
                        {{ config('app.name') }}
                    </div>
                    <flux:heading size="lg">{{ $tagline ?: 'Approvals, clearly tracked.' }}</flux:heading>
                </div>
            </div>
        </flux:card>
        </div>
    </div>
</x-pages::settings.layout>
