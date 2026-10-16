<?php

namespace Tests\Unit\Services;

use App\Services\RichTextSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RichTextSanitizerTest extends TestCase
{
    public function test_it_preserves_supported_formatting_and_removes_active_content(): void
    {
        $html = '<h2 onclick="steal()">Decision</h2><p>Hello <strong>team</strong>.</p>'
            .'<script>alert(1)</script><a href="javascript:alert(2)" style="color:red">bad link</a>';

        $clean = (new RichTextSanitizer)->sanitize($html);

        $this->assertStringContainsString('<h2>Decision</h2>', $clean);
        $this->assertStringContainsString('<strong>team</strong>', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('style=', $clean);
    }

    public function test_it_keeps_safe_links_and_plain_text(): void
    {
        $clean = (new RichTextSanitizer)->sanitize(
            'Plain introduction <a href="https://example.com/path" target="_blank">reference</a>',
        );

        $this->assertStringContainsString('Plain introduction', $clean);
        $this->assertStringContainsString('href="https://example.com/path"', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);
    }

    /**
     * RV-SEC-03: raw-text elements (xmp, noembed, noframes, plaintext, ...) must not
     * smuggle markup through unwrapping, on one pass or on re-sanitizing stored output.
     *
     * @return array<string, array{string}>
     */
    public static function rawTextPayloads(): array
    {
        $payload = '<img src=x onerror=alert(1)>';

        return [
            'xmp' => ["<xmp>{$payload}</xmp>"],
            'noembed' => ["<noembed>{$payload}</noembed>"],
            'noframes' => ["<noframes>{$payload}</noframes>"],
            'plaintext' => ["<plaintext>{$payload}"],
            'noscript' => ["<noscript>{$payload}</noscript>"],
            'textarea' => ["<textarea>{$payload}</textarea>"],
            'title' => ["<title>{$payload}</title>"],
            'template' => ["<template>{$payload}</template>"],
            'nested' => ["<xmp><noembed>{$payload}</noembed></xmp>"],
            'inside allowed' => ["<p>Hi <xmp>{$payload}</xmp></p>"],
        ];
    }

    #[DataProvider('rawTextPayloads')]
    public function test_raw_text_elements_cannot_smuggle_markup(string $html): void
    {
        $sanitizer = new RichTextSanitizer;
        $once = $sanitizer->sanitize($html);
        $twice = $sanitizer->sanitize($once);

        foreach ([$once, $twice] as $output) {
            $this->assertStringNotContainsString('<img', $output);
            $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son[a-z]+=/i', $output);
        }
    }

    public function test_output_contains_only_allowed_tags_and_is_idempotent(): void
    {
        $sanitizer = new RichTextSanitizer;
        $html = '<div><p class="x">One &amp; <em>two</em></p><span><b>three</b></span>'
            .'<pre><code>&lt;tag&gt;</code></pre><xmp><a href="https://example.com">x</a></xmp>'
            .'<ul><li>item</li></ul><a href="mailto:a@example.com" title="Mail">mail</a></div>';

        $once = $sanitizer->sanitize($html);

        preg_match_all('/<\/?([a-z0-9]+)/i', $once, $tags);
        $this->assertSame([], array_values(array_diff(
            array_unique(array_map('strtolower', $tags[1])),
            ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'code', 'pre', 'a'],
        )));
        $this->assertStringContainsString('One &amp; <em>two</em>', $once);
        $this->assertStringContainsString('&lt;tag&gt;', $once);
        $this->assertStringContainsString('href="mailto:a@example.com"', $once);
        $this->assertSame($once, $sanitizer->sanitize($once));
    }
}
