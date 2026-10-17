<?php

namespace App\Http;

use App\Models\User;
use App\Support\Branding;
use App\Support\CspNonce;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the drop-in static marketing site in website/ and injects
 * the auth header. Website files stay free of Blade and Livewire.
 */
final class PublicWebsite
{
    private const ALLOWED_EXTENSIONS = [
        'html', 'htm', 'css', 'js', 'map', 'svg', 'png', 'jpg', 'jpeg',
        'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'txt',
        'xml', 'json', 'webmanifest', 'pdf', 'mp4', 'webm',
    ];

    private const MIMES = [
        'html' => 'text/html; charset=UTF-8',
        'htm' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'txt' => 'text/plain; charset=UTF-8',
        'xml' => 'application/xml',
        'json' => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    public function __construct(private readonly ?string $root = null) {}

    public function root(): string
    {
        return $this->root ?? base_path('website');
    }

    public function resolve(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if ($path === '' || $path === '.') {
            $path = 'index.html';
        }

        if (str_contains($path, "\0") || str_contains($path, '..')) {
            return null;
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return null;
            }
        }

        $root = realpath($this->root());

        if ($root === false || ! is_dir($root)) {
            return null;
        }

        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);

        if (is_dir($candidate)) {
            $candidate .= DIRECTORY_SEPARATOR.'index.html';
        }

        if (! is_file($candidate)) {
            if (! str_contains(basename($path), '.') && is_file($candidate.'.html')) {
                $candidate .= '.html';
            } else {
                return null;
            }
        }

        $real = realpath($candidate);

        if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $extension = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        return $real;
    }

    public function inject(string $html, ?User $user, bool $registrationOpen): string
    {
        // The drop-in site and custom code are owner content: their scripts get
        // this response's nonce so the Content Security Policy lets them run.
        $html = CspNonce::addToScripts($this->injectCustomCode($html));
        $nav = $this->authNav($user, $registrationOpen);

        $replaced = preg_replace(
            '/<nav\s+data-approvedesk-auth\b[^>]*>\s*<\/nav>/i',
            $nav,
            $html,
            1,
            $count,
        );

        if (is_string($replaced) && $count > 0) {
            return $replaced;
        }

        if (preg_match('/<body[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $offset)."\n".$nav.substr($html, $offset);
        }

        return $nav.$html;
    }

    public function response(string $path, ?User $user): Response|BinaryFileResponse
    {
        $file = $this->resolve($path);

        abort_if($file === null, 404);

        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $mime = self::MIMES[$extension] ?? 'application/octet-stream';

        if (in_array($extension, ['html', 'htm'], true)) {
            $html = $this->inject((string) file_get_contents($file), $user, Branding::registrationOpen());

            return response($html, 200, [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->file($file, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authNav(?User $user, bool $registrationOpen): string
    {
        if ($user !== null) {
            return '<nav data-approvedesk-auth data-state="user"><a href="'
                .e(route('dashboard')).'" data-auth="dashboard">Dashboard</a></nav>';
        }

        $links = '<a href="'.e(route('login')).'" data-auth="login">Log in</a>';

        if ($registrationOpen) {
            $links .= '<a href="'.e(route('register')).'" data-auth="register">Sign up</a>';
        }

        return '<nav data-approvedesk-auth data-state="guest">'.$links.'</nav>';
    }

    private function injectCustomCode(string $html): string
    {
        $code = Branding::customCodeHtml();

        if ($code === null) {
            return $html;
        }

        $snippet = "<!-- approvedesk-custom-code -->\n".$code."\n";

        if (stripos($html, '</head>') !== false) {
            return (string) preg_replace('/<\/head>/i', $snippet.'</head>', $html, 1);
        }

        if (preg_match('/<body[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) $match[0][1];

            return substr($html, 0, $offset).$snippet.substr($html, $offset);
        }

        return $snippet.$html;
    }
}
