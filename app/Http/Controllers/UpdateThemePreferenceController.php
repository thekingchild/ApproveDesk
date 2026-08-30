<?php

namespace App\Http\Controllers;

use App\Actions\Themes\UpdateUserThemePreferenceAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateThemePreferenceController extends Controller
{
    public function __invoke(Request $request, UpdateUserThemePreferenceAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'theme_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $action->execute($user, isset($validated['theme_id']) ? (int) $validated['theme_id'] : null);

        return redirect()->route('appearance.edit')->with('status', __('Application theme saved.'));
    }
}
