<?php

namespace App\Actions\Themes;

use App\Models\Theme;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SetThemeEnabledAction
{
    public function execute(Theme $theme, bool $enabled, User $actor): Theme
    {
        Gate::forUser($actor)->authorize('enable', $theme);

        return DB::transaction(function () use ($theme, $enabled): Theme {
            $themes = Theme::query()->orderBy('id')->lockForUpdate()->get();
            $locked = $themes->firstWhere('id', $theme->id);
            if (! $locked instanceof Theme) {
                abort(404);
            }
            if (! $enabled && $locked->is_default) {
                throw ValidationException::withMessages(['theme' => 'The organization default must remain enabled.']);
            }
            if (! $enabled && $themes->where('is_enabled', true)->whereNull('archived_at')->count() <= 1) {
                throw ValidationException::withMessages(['theme' => 'At least one theme must remain enabled.']);
            }
            if ($enabled && $locked->archived_at !== null) {
                throw ValidationException::withMessages(['theme' => 'Restore an archived theme before enabling it.']);
            }
            $locked->update(['is_enabled' => $enabled]);

            return $locked->fresh();
        });
    }
}
