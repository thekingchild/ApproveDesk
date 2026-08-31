<?php

namespace Tests\Unit\Support;

use App\Support\OutboundPin;
use Tests\TestCase;

class OutboundPinTest extends TestCase
{
    public function test_ipv6_literal_host_and_address_are_bracketed_for_curl_resolve(): void
    {
        $ip = '2606:4700:4700::1111';
        $pin = new OutboundPin(
            "https://[{$ip}]/push",
            $ip,
            443,
            $ip,
            [$ip],
        );

        $entry = "[{$ip}]:443:[{$ip}]";

        $this->assertSame([$entry], OutboundPin::curlResolveEntries($pin));

        $handle = curl_init("https://[{$ip}]/push");
        curl_setopt_array($handle, [
            CURLOPT_RESOLVE => [$entry],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 50,
            CURLOPT_TIMEOUT_MS => 50,
            CURLOPT_PROXY => '',
        ]);
        curl_exec($handle);

        $this->assertStringNotContainsString(
            'Could not parse CURLOPT_RESOLVE entry',
            curl_error($handle),
        );
    }

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
            'hooks.example.test:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]',
        ], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function test_resolve_entries_group_unique_addresses_for_each_host_and_port(): void
    {
        $first = new OutboundPin(
            'https://push.example.test/one',
            'push.example.test',
            443,
            '93.184.216.34',
            ['93.184.216.34', '2606:4700:4700::1111', '93.184.216.34'],
        );
        $second = new OutboundPin(
            'https://push.example.test/two',
            'push.example.test',
            443,
            '1.1.1.1',
            ['2606:4700:4700::1111', '1.1.1.1'],
        );
        $differentPort = new OutboundPin(
            'https://push.example.test:8443/three',
            'push.example.test',
            8443,
            '8.8.8.8',
            ['8.8.8.8'],
        );

        $this->assertSame([
            'push.example.test:443:93.184.216.34,[2606:4700:4700::1111],1.1.1.1',
            'push.example.test:8443:8.8.8.8',
        ], OutboundPin::curlResolveEntries([$first, $second, $differentPort]));
    }

    public function test_grouped_resolve_entry_keeps_both_addresses_in_libcurl_dns_cache(): void
    {
        $pin = new OutboundPin(
            'http://multi-address.example.test:9/',
            'multi-address.example.test',
            9,
            '127.0.0.1',
            ['127.0.0.1', '127.0.0.2'],
        );
        $verbose = fopen('php://temp', 'w+');
        $this->assertIsResource($verbose);
        $handle = curl_init($pin->url);
        curl_setopt_array($handle, [
            CURLOPT_RESOLVE => OutboundPin::curlResolveEntries($pin),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 50,
            CURLOPT_TIMEOUT_MS => 100,
            CURLOPT_PROXY => '',
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => $verbose,
        ]);

        curl_exec($handle);
        rewind($verbose);
        $debug = stream_get_contents($verbose);

        $this->assertIsString($debug);
        $this->assertStringContainsString('IPv4: 127.0.0.1, 127.0.0.2', $debug);
        $this->assertStringNotContainsString('old addresses discarded', $debug);
    }

    public function test_libcurl_does_not_apply_an_undotted_pin_to_a_trailing_dot_url(): void
    {
        $pin = new OutboundPin(
            'http://trailing-dot-pin.invalid.:9/',
            'trailing-dot-pin.invalid',
            9,
            '127.0.0.1',
            ['127.0.0.1'],
        );
        $verbose = fopen('php://temp', 'w+');
        $this->assertIsResource($verbose);
        $handle = curl_init($pin->url);
        curl_setopt_array($handle, [
            CURLOPT_RESOLVE => OutboundPin::curlResolveEntries($pin),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 50,
            CURLOPT_TIMEOUT_MS => 100,
            CURLOPT_PROXY => '',
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => $verbose,
        ]);

        curl_exec($handle);
        rewind($verbose);
        $debug = stream_get_contents($verbose);

        $this->assertIsString($debug);
        $this->assertStringContainsString('Could not resolve host: trailing-dot-pin.invalid.', $debug);
        $this->assertStringNotContainsString('Hostname trailing-dot-pin.invalid. was found in DNS cache', $debug);
    }
}
