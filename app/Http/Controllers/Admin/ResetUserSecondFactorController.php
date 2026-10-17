<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\ResetUserSecondFactorAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ResetUserSecondFactorController
{
    public function __invoke(Request $request, User $user, ResetUserSecondFactorAction $action): RedirectResponse
    {
        Gate::authorize('recover', $user);

        try {
            $action->execute($user, $request->user());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('admin.users.index')
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('admin.users.index')
            ->with('recovery-status', __('Authenticator, recovery codes, and passkeys were removed.'));
    }
}
