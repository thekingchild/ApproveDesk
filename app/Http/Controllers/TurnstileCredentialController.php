<?php

namespace App\Http\Controllers;

use App\Actions\Security\StoreTurnstileCredentialsAction;
use App\Http\Requests\StoreTurnstileSettingsRequest;
use Illuminate\Http\RedirectResponse;

class TurnstileCredentialController extends Controller
{
    public function __invoke(StoreTurnstileSettingsRequest $request, StoreTurnstileCredentialsAction $store): RedirectResponse
    {
        $store->execute($request->settings());

        return redirect()
            ->route('settings.security-privacy')
            ->with('status', $request->boolean('clear')
                ? __('Turnstile secret cleared.')
                : __('Bot protection saved.'));
    }
}
