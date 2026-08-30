<?php

namespace App\Actions\Themes;

use App\Models\Theme;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ArchiveThemeAction
{
    public function execute(Theme $theme, User $actor): Theme
    {
        Gate::forUser($actor)->authorize('archive', $theme);

        return DB::transaction(function () use ($theme): Theme {
            $themes = Theme::query()->orderBy('id')->lockForUpdate()->get();
            $locked = $themes->firstWhere('id', $theme->id);
            if (! $locked instanceof Theme) {
                abort(404);
            }
            if ($locked->is_builtin) {
                throw ValidationException::withMessages(['theme' => 'Built-in themes cannot be archived.']);
            }
            if ($locked->is_default) {
                throw ValidationException::withMessages(['theme' => 'Choose another organization default first.']);
            }
            $locked->update(['archived_at' => now(), 'is_enabled' => false]);

            return $locked->fresh();
        });
    }
}
