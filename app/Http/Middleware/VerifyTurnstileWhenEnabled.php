<?php

namespace App\Http\Middleware;

use App\Actions\Security\VerifyTurnstileAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTurnstileWhenEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->routeIs(
            'login.store',
            'register.store',
            'password.email',
            'password.update',
        )) {
            app(VerifyTurnstileAction::class)->execute($request);
        }

        return $next($request);
    }
}
