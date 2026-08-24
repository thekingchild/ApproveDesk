<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the two installer invariants:
 *
 *  - while the app is NOT installed: every web request is sent to /install
 *    (except the installer itself and Livewire update endpoints);
 *  - once installed: /install disappears (404).
 */
class EnsureAppConfigured
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installer->isActive()) {
            return $next($request);
        }

        if ($this->installer->isInstalled()) {
            if ($request->is('install') || $request->is('install/*')) {
                abort(404);
            }

            return $next($request);
        }

        $this->installer->ensureBootstrapEnvironment();

        $allowed = $request->is('install') || $request->is('install/*')
            || $request->is('livewire/*') || $request->is('up');

        if (! $allowed) {
            return redirect()->route('install.welcome');
        }

        return $next($request);
    }
}
