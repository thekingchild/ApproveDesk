<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\ApplySystemRuntimeConfig;
use App\Http\Middleware\CorrelateRequests;
use App\Http\Middleware\EnsureAppConfigured;
use App\Http\Middleware\EnsureEmailApprovalSecondFactor;
use App\Http\Middleware\EnsureSecondFactorWhenRequired;
use App\Http\Middleware\EnsureSystemOwnerReauthenticated;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PrepareFirstRunEnvironment;
use App\Http\Middleware\RedirectUnconfirmedRegistration;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyTurnstileWhenEnabled;
use App\Support\LocalTime;
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
    // Listeners are registered explicitly in AppServiceProvider. Discovery would
    // register each of them a second time, so every notification went out twice.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ApplySystemRuntimeConfig::class);
        $middleware->prepend(PrepareFirstRunEnvironment::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(CorrelateRequests::class);
        $middleware->web([
            EnsureAppConfigured::class,
            EnsureUserIsActive::class,
            RedirectUnconfirmedRegistration::class,
            EnsureSecondFactorWhenRequired::class,
            VerifyTurnstileWhenEnabled::class,
        ]);
        $middleware->alias([
            'email-approval.second-factor' => EnsureEmailApprovalSecondFactor::class,
            'owner.reauthenticated' => EnsureSystemOwnerReauthenticated::class,
        ]);
        // Set by JavaScript and read only as a display preference (RV-ARC-17).
        $middleware->encryptCookies(except: [LocalTime::COOKIE]);
        $middleware->validateCsrfTokens(except: [
            'integrations/telegram/webhook',
            'integrations/slack/interactions',
            'integrations/teams/actions',
            'csp-report',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // A refused domain rule is the user's 422, not a server error (RV-ARC-19).
        $exceptions->render(function (DomainException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $exception->userMessage()], 422);
            }

            if (! $request->isMethod('GET')) {
                return back()->withErrors(['domain' => $exception->userMessage()])->withInput();
            }

            return null;
        });
    })->create();
