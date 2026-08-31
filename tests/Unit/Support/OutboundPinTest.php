<?php

namespace Tests\Unit\Support;

use App\Support\OutboundPin;
use Tests\TestCase;

class OutboundPinTest extends TestCase
{
    public function test_http_options_disable_redirects_verify_tls_and_pin_every_address(): void
    {
        $pin = new OutboundPin(
            'https://hooks.example.test/approvedesk',
            'hooks.example.test',
            443,
            '93.184.216.34',
            ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
        );

        $options = $pin->httpOptions();

        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['verify']);
        $this->assertSame([
            'hooks.example.test:443:93.184.216.34',
            'hooks.example.test:443:[2606:2800:220:1:248:1893:25c8:1946]',
        ], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function test_resolve_entries_include_all_pins_and_are_deduplicated(): void
    {
        $first = new OutboundPin(
            'https://push.example.test/one',
            'push.example.test',
            443,
            '93.184.216.34',
            ['93.184.216.34', '93.184.216.34'],
        );
        $second = new OutboundPin(
            'https://updates.example.test/two',
            'updates.example.test',
            443,
            '1.1.1.1',
            ['1.1.1.1'],
        );

        $this->assertSame([
            'push.example.test:443:93.184.216.34',
            'updates.example.test:443:1.1.1.1',
        ], OutboundPin::curlResolveEntries([$first, $second]));
    }
}
