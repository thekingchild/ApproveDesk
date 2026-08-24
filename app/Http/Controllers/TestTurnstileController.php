<?php

namespace App\Http\Controllers;

use App\Support\Turnstile;
use Illuminate\Http\RedirectResponse;

class TestTurnstileController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $result = Turnstile::probe();

        return redirect()
            ->route('settings.security-privacy')
            ->with('turnstile_test', $result['message'])
            ->with('turnstile_test_ok', $result['reachable']);
    }
}
