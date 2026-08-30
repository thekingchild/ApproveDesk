<?php

use App\Actions\Themes\ArchiveThemeAction;
use App\Actions\Themes\SaveThemeAction;
use App\Actions\Themes\SetDefaultThemeAction;
use App\Actions\Themes\SetThemeEnabledAction;
use App\Models\Theme;
use App\Models\User;
use App\Services\Themes\ThemeService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Application themes')] class extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $key = '';

    public string $primaryLight = '#166534';

    public string $foregroundLight = '#FFFFFF';

    public string $primaryDark = '#4ADE80';

    public string $foregroundDark = '#052E16';

    public function mount(): void
    {
        $this->authorize('viewAny', Theme::class);
    }

    #[Computed]
    public function themes(): \Illuminate\Database\Eloquent\Collection
    {
        return Theme::query()
            ->orderByDesc('is_default')
            ->orderByDesc('is_enabled')
            ->orderBy('name')
            ->get();
    }

    public function createTheme(): void
    {
        $this->authorize('create', Theme::class);
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $theme = Theme::query()->findOrFail($id);
        $this->authorize('update', $theme);
        if ($theme->is_builtin) {
            throw \Illuminate\Validation\ValidationException::withMessages(['theme' => __('Built-in themes are immutable.')]);
        }
        $this->editingId = $theme->id;
        $this->name = $theme->name;
        $this->key = $theme->key;
        $this->primaryLight = $theme->primary_light;
        $this->foregroundLight = $theme->foreground_light;
        $this->primaryDark = $theme->primary_dark;
        $this->foregroundDark = $theme->foreground_dark;
        $this->resetValidation();
    }

    public function save(SaveThemeAction $action): void
    {
        $target = $this->editingId ? Theme::query()->findOrFail($this->editingId) : null;
        $actor = Auth::user();
        assert($actor instanceof User);
        $saved = $action->execute($target, [
            'name' => $this->name,
            'key' => $this->key,
            'primary_light' => $this->primaryLight,
            'foreground_light' => $this->foregroundLight,
            'primary_dark' => $this->primaryDark,
            'foreground_dark' => $this->foregroundDark,
        ], $actor);

        $this->editingId = $saved->id;
        unset($this->themes);
        Flux::toast(__('Application theme saved.'), variant: 'success');
    }

    public function toggleEnabled(int $id, SetThemeEnabledAction $action): void
    {
        $theme = Theme::query()->findOrFail($id);
        $actor = Auth::user();
        assert($actor instanceof User);
        $action->execute($theme, ! $theme->is_enabled, $actor);
        unset($this->themes);
        Flux::toast(__('Theme availability updated.'), variant: 'success');
    }

    public function makeDefault(int $id, SetDefaultThemeAction $action): void
    {
        $theme = Theme::query()->findOrFail($id);
        $actor = Auth::user();
        assert($actor instanceof User);
        $action->execute($theme, $actor);
        unset($this->themes);
        Flux::toast(__('Organization default theme updated.'), variant: 'success');
    }

    public function archive(int $id, ArchiveThemeAction $action): void
    {
        $theme = Theme::query()->findOrFail($id);
        $actor = Auth::user();
        assert($actor instanceof User);
        $action->execute($theme, $actor);
        if ($this->editingId === $id) {
            $this->resetForm();
        }
        unset($this->themes);
        Flux::toast(__('Custom theme archived.'), variant: 'success');
    }

    public function contrast(string $primary, string $foreground): string
    {
        return number_format(app(ThemeService::class)->contrastRatio($primary, $foreground), 2);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->name = '';
        $this->key = '';
        $this->primaryLight = '#166534';
        $this->foregroundLight = '#FFFFFF';
        $this->primaryDark = '#4ADE80';
        $this->foregroundDark = '#052E16';
    }
}; ?>

<div class="space-y-8">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Application themes')]]" :back="route('dashboard')" back-label="Back to dashboard" />
    <x-ui.page-header :title="__('Application themes')" :description="__('Curate fixed, accessible color palettes for signed-in pages.')" help-article="application-themes" />

    <div class="grid gap-8 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <flux:card>
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="sm">{{ $editingId ? __('Edit custom theme') : __('New custom theme') }}</flux:heading>
                @if ($editingId)
                    <flux:button size="sm" variant="ghost" wire:click="createTheme">{{ __('Start new') }}</flux:button>
                @endif
            </div>
            <flux:text class="mt-2">{{ __('Use exactly six hexadecimal digits after #. Each primary/foreground pair must reach WCAG 4.5:1 contrast.') }}</flux:text>
            <form wire:submit="save" class="mt-5 space-y-4">
                <flux:input wire:model="name" :label="__('Name')" maxlength="100" required />
                <flux:error name="name" />
                <flux:input wire:model="key" :label="__('Stable key')" maxlength="60" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required />
                <flux:error name="key" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><flux:input wire:model="primaryLight" :label="__('Light primary')" maxlength="7" pattern="#[0-9A-Fa-f]{6}" required /><flux:error name="primary_light" /></div>
                    <div><flux:input wire:model="foregroundLight" :label="__('Light foreground')" maxlength="7" pattern="#[0-9A-Fa-f]{6}" required /><flux:error name="foreground_light" /></div>
                    <div><flux:input wire:model="primaryDark" :label="__('Dark primary')" maxlength="7" pattern="#[0-9A-Fa-f]{6}" required /><flux:error name="primary_dark" /></div>
                    <div><flux:input wire:model="foregroundDark" :label="__('Dark foreground')" maxlength="7" pattern="#[0-9A-Fa-f]{6}" required /><flux:error name="foreground_dark" /></div>
                </div>
                <flux:error name="theme" />
                <flux:button type="submit" variant="primary">{{ __('Save custom theme') }}</flux:button>
            </form>
        </flux:card>

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($this->themes as $theme)
                <flux:card wire:key="theme-{{ $theme->id }}" class="space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <flux:heading size="sm">{{ $theme->name }}</flux:heading>
                            <flux:text>{{ $theme->is_builtin ? __('Built-in') : __('Custom') }} · {{ $theme->key }}</flux:text>
                        </div>
                        <div class="flex flex-wrap justify-end gap-1">
                            @if ($theme->is_default)<flux:badge>{{ __('Default') }}</flux:badge>@endif
                            <flux:badge>{{ $theme->archived_at ? __('Archived') : ($theme->is_enabled ? __('Enabled') : __('Disabled')) }}</flux:badge>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-lg border p-3" style="background-color: {{ $theme->primary_light }}; color: {{ $theme->foreground_light }}">
                            {{ __('Light') }} · {{ $this->contrast($theme->primary_light, $theme->foreground_light) }}:1
                        </div>
                        <div class="rounded-lg border p-3" style="background-color: {{ $theme->primary_dark }}; color: {{ $theme->foreground_dark }}">
                            {{ __('Dark') }} · {{ $this->contrast($theme->primary_dark, $theme->foreground_dark) }}:1
                        </div>
                    </div>

                    @if (! $theme->archived_at)
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" wire:click="toggleEnabled({{ $theme->id }})" :disabled="$theme->is_default">{{ $theme->is_enabled ? __('Disable') : __('Enable') }}</flux:button>
                            @if (! $theme->is_default)
                                <flux:button size="sm" variant="primary" wire:click="makeDefault({{ $theme->id }})">{{ __('Make default') }}</flux:button>
                            @endif
                            @if (! $theme->is_builtin)
                                <flux:button size="sm" wire:click="edit({{ $theme->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="archive({{ $theme->id }})" wire:confirm="{{ __('Archive this custom theme? People using it will fall back to the organization default.') }}">{{ __('Archive') }}</flux:button>
                            @endif
                        </div>
                    @endif
                </flux:card>
            @endforeach
        </div>
    </div>
</div>
