<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\ResetUserPasswordAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ResetUserPasswordController
{
    public function __invoke(Request $request, User $user, ResetUserPasswordAction $action): RedirectResponse
    {
        $this->authorizeRecover($request, $user);

        try {
            $action->execute($user, $request->user());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('admin.users.index')
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('admin.users.index')
            ->with('recovery-status', __('Password invalidated and a reset link was emailed.'));
    }

    private function authorizeRecover(Request $request, User $user): void
    {
        Gate::authorize('recover', $user);
    }
}
