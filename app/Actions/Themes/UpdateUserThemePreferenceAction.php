<?php

namespace App\Actions\Themes;

use App\Models\Theme;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class UpdateUserThemePreferenceAction
{
    public function execute(User $user, ?int $themeId): User
    {
        if ($themeId === null) {
            $user->update(['theme_id' => null]);

            return $user->fresh();
        }
        $theme = Theme::query()->whereKey($themeId)->where('is_enabled', true)->whereNull('archived_at')->first();
        if (! $theme instanceof Theme) {
            throw ValidationException::withMessages(['theme_id' => 'Choose an available application theme.']);
        }
        $user->update(['theme_id' => $theme->id]);

        return $user->fresh();
    }
}
