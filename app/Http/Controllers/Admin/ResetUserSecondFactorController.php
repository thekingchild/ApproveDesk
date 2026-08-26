<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\ResetUserSecondFactorAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ResetUserSecondFactorController
{
    public function __invoke(Request $request, User $user, ResetUserSecondFactorAction $action): RedirectResponse
    {
        abort_unless($request->user()?->can('recover', $user), 403);

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
