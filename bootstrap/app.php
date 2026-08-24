<?php

use App\Http\Middleware\EnsureAppConfigured;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RedirectUnconfirmedRegistration;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web([
            EnsureAppConfigured::class,
            EnsureUserIsActive::class,
            RedirectUnconfirmedRegistration::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'integrations/telegram/webhook',
            'integrations/slack/interactions',
            'integrations/teams/actions',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
