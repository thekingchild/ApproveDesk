<?php

namespace Tests\Unit\Support;

use App\Support\OutboundPin;
use Tests\TestCase;

class OutboundPinTest extends TestCase
{
    public function test_http_options_disable_redirects_verify_tls_and_pin_via_curl_resolve(): void
    {
        $pin = new OutboundPin(
            'https://hooks.example.test/approvedesk',
            'hooks.example.test',
            443,
            '203.0.113.10',
            ['203.0.113.10'],
        );

        $options = $pin->httpOptions();

        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['verify']);
        $this->assertArrayHasKey('curl', $options);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $options['curl']);
        $this->assertSame(['hooks.example.test:443:203.0.113.10'], $options['curl'][CURLOPT_RESOLVE]);
    }
}
