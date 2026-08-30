<?php

namespace App\Actions\Themes;

use App\Models\Theme;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SetDefaultThemeAction
{
    public function execute(Theme $theme, User $actor): Theme
    {
        Gate::forUser($actor)->authorize('setDefault', $theme);

        return DB::transaction(function () use ($theme): Theme {
            $themes = Theme::query()->orderBy('id')->lockForUpdate()->get();
            $locked = $themes->firstWhere('id', $theme->id);
            if (! $locked instanceof Theme) {
                abort(404);
            }
            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages(['theme' => 'An archived theme cannot be the default.']);
            }
            Theme::query()->where('is_default', true)->update(['is_default' => false]);
            $locked->update(['is_default' => true, 'is_enabled' => true]);

            return $locked->fresh();
        });
    }
}
