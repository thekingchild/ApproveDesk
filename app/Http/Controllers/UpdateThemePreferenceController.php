<?php

namespace App\Http\Controllers;

use App\Actions\Themes\UpdateUserThemePreferenceAction;
use App\Http\Requests\UpdateThemePreferenceRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UpdateThemePreferenceController extends Controller
{
    public function __invoke(UpdateThemePreferenceRequest $request, UpdateUserThemePreferenceAction $action): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $action->execute($user, isset($validated['theme_id']) ? (int) $validated['theme_id'] : null);

        return redirect()->route('appearance.edit')->with('status', __('Application theme saved.'));
    }
}
