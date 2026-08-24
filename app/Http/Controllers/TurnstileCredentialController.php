<?php

namespace App\Http\Controllers;

use App\Actions\Security\StoreTurnstileCredentialsAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TurnstileCredentialController extends Controller
{
    public function __invoke(Request $request, StoreTurnstileCredentialsAction $store): RedirectResponse
    {
        $validated = $request->validate([
            'secret' => ['nullable', 'string', 'max:255'],
            'clear' => ['sometimes', 'boolean'],
        ]);

        $store->execute([
            'secret' => $validated['secret'] ?? null,
            'clear' => $request->boolean('clear'),
        ]);

        return redirect()
            ->route('settings.security-privacy')
            ->with('status', $request->boolean('clear')
                ? __('Turnstile secret cleared.')
                : __('Turnstile secret saved.'));
    }
}
