<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use App\Support\SystemSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ApplySystemRuntimeConfig
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installer->isInstalled()) {
            return $next($request);
        }

        try {
            SystemSettings::applyRuntimeConfig();
        } catch (Throwable) {
            // Settings may be unavailable during first-run or test bootstrap.
        }

        return $next($request);
    }
}
