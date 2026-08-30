<?php

use App\Actions\Themes\UpdateUserThemePreferenceAction;
use App\Models\Theme;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component
{
    public string $themeId = '';

    public function mount(): void
    {
        $user = Auth::user();
        assert($user instanceof User);
        $this->themeId = $user->theme_id ? (string) $user->theme_id : '';
    }

    #[Computed]
    public function themes(): \Illuminate\Database\Eloquent\Collection
    {
        return Theme::query()
            ->where('is_enabled', true)
            ->whereNull('archived_at')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function saveTheme(UpdateUserThemePreferenceAction $action): void
    {
        $user = Auth::user();
        assert($user instanceof User);
        $action->execute($user, $this->themeId === '' ? null : (int) $this->themeId);
        Flux::toast(__('Application theme saved. It applies on the next page load.'), variant: 'success');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Choose an application theme independently from Light, Dark, or System mode.')">
        <div class="space-y-8">
            <div>
                <flux:heading size="sm">{{ __('Light and dark mode') }}</flux:heading>
                <flux:text class="mt-1">{{ __('System follows your device. Your application theme colors remain the same choice in every mode.') }}</flux:text>
                <flux:radio.group class="mt-4" x-data variant="segmented" x-model="$flux.appearance">
                    <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
                    <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
                    <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
                </flux:radio.group>
            </div>

            <form wire:submit="saveTheme" class="space-y-4">
                <div>
                    <flux:heading size="sm">{{ __('Application theme') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Choose one of the palettes enabled by the System owner, or use the organization default.') }}</flux:text>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-4">
                        <input type="radio" wire:model="themeId" value="">
                        <span>
                            <span class="block font-medium">{{ __('Organization default') }}</span>
                            <span class="block text-sm text-zinc-500">{{ __('Automatically follows the current default palette.') }}</span>
                        </span>
                    </label>
                    @foreach ($this->themes as $theme)
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-4" wire:key="appearance-theme-{{ $theme->id }}">
                            <input type="radio" wire:model="themeId" value="{{ $theme->id }}">
                            <span class="flex min-w-0 flex-1 items-center justify-between gap-3">
                                <span>
                                    <span class="block font-medium">{{ $theme->name }}</span>
                                    <span class="block text-sm text-zinc-500">{{ $theme->is_default ? __('Current organization default') : __('Available palette') }}</span>
                                </span>
                                <span class="flex" aria-hidden="true">
                                    <span class="size-6 rounded-full border" style="background-color: {{ $theme->primary_light }}"></span>
                                    <span class="-ms-2 size-6 rounded-full border" style="background-color: {{ $theme->primary_dark }}"></span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <flux:error name="theme_id" />
                <flux:button type="submit" variant="primary">{{ __('Save application theme') }}</flux:button>
            </form>
        </div>
    </x-pages::settings.layout>
</section>
