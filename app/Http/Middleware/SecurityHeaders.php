<?php

namespace App\Http\Middleware;

use App\Support\Branding;
use App\Support\CspNonce;
use App\Support\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Before rendering, so Blade, Livewire and Flux can stamp it on their scripts (ADR 0005).
        Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // Laravel's local debug error page inlines its own scripts; production never runs with debug on.
        if (! (config('app.debug') && $response->getStatusCode() >= 500)) {
            $response->headers->set('Content-Security-Policy', $this->policy(strict: false, secure: $request->secure()));
            // The Reporting API only delivers to HTTPS; on plain HTTP browsers use report-uri.
            if ($request->secure()) {
                $response->headers->set('Reporting-Endpoints', 'csp-endpoint="'.route('csp.report').'"');
            }

            // Step 2: while Alpine runs in its CSP build, report what the strict
            // policy (no 'unsafe-eval') would block, without blocking it yet.
            if (config('livewire.csp_safe')) {
                $response->headers->set('Content-Security-Policy-Report-Only', $this->policy(strict: true, secure: $request->secure()));
            }
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(bool $strict, bool $secure): string
    {
        // ADR 0005: inline scripts need this response's nonce, so injected <script>
        // tags and on* attributes never run. The enforced policy keeps
        // 'unsafe-eval' for Alpine's standard build; the strict one (report-only
        // until step 3) drops it.
        $script = "script-src 'self' 'nonce-".CspNonce::current()."'".($strict ? '' : " 'unsafe-eval'");
        $connect = "connect-src 'self'";
        $frame = "frame-src 'self'";
        $img = "img-src 'self' data: blob:";

        if (Turnstile::enabled()) {
            $script .= ' https://challenges.cloudflare.com';
            $connect .= ' https://challenges.cloudflare.com';
            $frame .= ' https://challenges.cloudflare.com';
        }

        // `npm run dev`: modules and hot reload come from the Vite dev server.
        if (Vite::isRunningHot()) {
            $dev = rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');
            $script .= ' '.$dev;
            $connect .= ' '.$dev.' '.preg_replace('#^http#', 'ws', $dev);
        }

        foreach (Branding::customCodeOrigins() as $origin) {
            $script .= ' '.$origin;
            $connect .= ' '.$origin;
            $frame .= ' '.$origin;
            $img .= ' '.$origin;
        }

        return implode('; ', [
            "default-src 'self'",
            $script,
            "style-src 'self' 'unsafe-inline'",
            $img,
            "font-src 'self' data:",
            $connect,
            $frame,
            "media-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            'report-uri '.route('csp.report', absolute: false),
            ...($secure ? ['report-to csp-endpoint'] : []),
        ]);
    }
}
