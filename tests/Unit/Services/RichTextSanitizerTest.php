<?php

namespace Tests\Unit\Services;

use App\Services\RichTextSanitizer;
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
}
