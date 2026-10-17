<?php

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * The per-response script nonce (ADR 0005). SecurityHeaders creates it before
 * the page renders; Livewire and Flux read it through Vite::cspNonce().
 */
final class CspNonce
{
    public static function current(): ?string
    {
        return Vite::cspNonce();
    }

    /**
     * Give every <script> in trusted owner HTML (custom code, the drop-in
     * public website) this response's nonce, so it keeps running under the
     * policy. Inline event attributes (onclick=...) still do not run.
     */
    public static function addToScripts(string $html): string
    {
        $nonce = self::current();
        if ($nonce === null || $nonce === '') {
            return $html;
        }

        return (string) preg_replace('/<script\b(?![^>]*\bnonce=)/i', '<script nonce="'.e($nonce).'"', $html);
    }
}
