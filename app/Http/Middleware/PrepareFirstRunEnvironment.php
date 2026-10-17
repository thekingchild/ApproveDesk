<?php

namespace App\Http\Middleware;

use App\Services\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The wizard copies .env and generates APP_KEY, but EncryptCookies and
 * StartSession run before EnsureAppConfigured. On a fresh cPanel zip that
 * work must happen first or /install is a 500.
 */
final class PrepareFirstRunEnvironment
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installer->isInstalled()) {
            return $next($request);
        }

        $this->installer->ensureBootstrapEnvironment();

        return $next($request);
    }
}
