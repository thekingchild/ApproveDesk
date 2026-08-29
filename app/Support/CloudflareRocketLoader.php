<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * Cloudflare Rocket Loader only honours data-cfasync when it appears before
 * src. Vite and Livewire append extra attributes after src, and Livewire
 * injects its tag after the middleware stack, so the HTML is rewritten here.
 */
final class CloudflareRocketLoader
{
    public static function protect(Response $response): void
    {
        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return;
        }

        $content = $response->getContent();
        if (! is_string($content) || ! str_contains($content, '<script')) {
            return;
        }

        $rewritten = preg_replace_callback(
            '/<script\b([^>]*)>/i',
            function (array $match): string {
                $attributes = $match[1];
                if (! preg_match('/\bsrc\s*=/i', $attributes)) {
                    return $match[0];
                }

                $attributes = (string) preg_replace('/\s*data-cfasync\s*=\s*(["\']).*?\1/i', '', $attributes);
                $attributes = (string) preg_replace('/\s*data-cfasync\s*=\s*[^\s>]*/i', '', $attributes);

                return '<script data-cfasync="false"'.$attributes.'>';
            },
            $content,
        );

        if (is_string($rewritten)) {
            $response->setContent($rewritten);
        }
    }
}
