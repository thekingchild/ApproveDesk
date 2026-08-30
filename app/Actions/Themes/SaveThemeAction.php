<?php

namespace App\Actions\Themes;

use App\Models\Theme;
use App\Models\User;
use App\Services\Themes\ThemeService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveThemeAction
{
    public function __construct(private readonly ThemeService $themes) {}

    /**
     * @param  array{name: string, key: string, primary_light: string, primary_dark: string, foreground_light: string, foreground_dark: string}  $data
     */
    public function execute(?Theme $theme, array $data, User $actor): Theme
    {
        Gate::forUser($actor)->authorize($theme ? 'update' : 'create', $theme ?? Theme::class);
        if ($theme?->is_builtin) {
            throw ValidationException::withMessages(['theme' => 'Built-in themes are immutable.']);
        }
        $id = $theme?->id;
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('themes', 'key')->ignore($id)],
            'primary_light' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'primary_dark' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'foreground_light' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'foreground_dark' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ])->validate();
        foreach (['primary_light', 'primary_dark', 'foreground_light', 'foreground_dark'] as $token) {
            $validated[$token] = strtoupper($validated[$token]);
        }
        if ($this->themes->contrastRatio($validated['primary_light'], $validated['foreground_light']) < ThemeService::MINIMUM_CONTRAST_RATIO) {
            throw ValidationException::withMessages([
                'foreground_light' => 'The light primary and foreground colors must have a contrast ratio of at least 4.5:1.',
            ]);
        }
        if ($this->themes->contrastRatio($validated['primary_dark'], $validated['foreground_dark']) < ThemeService::MINIMUM_CONTRAST_RATIO) {
            throw ValidationException::withMessages([
                'foreground_dark' => 'The dark primary and foreground colors must have a contrast ratio of at least 4.5:1.',
            ]);
        }
        $theme ??= new Theme(['is_builtin' => false, 'is_enabled' => true, 'is_default' => false]);
        $theme->fill($validated)->save();

        return $theme->fresh();
    }
}
