<?php

namespace Tests\Unit\Support;

use App\Support\OutboundDestination;
use InvalidArgumentException;
use Tests\Support\ArrayDnsResolver;
use Tests\TestCase;

class OutboundDestinationTest extends TestCase
{
    public function test_literal_private_ips_are_rejected(): void
    {
        $destination = new OutboundDestination(new ArrayDnsResolver([], []));

        $this->expectException(InvalidArgumentException::class);
        $destination->validate('https://127.0.0.1/hooks');
    }

    public function test_localhost_is_rejected(): void
    {
        $destination = new OutboundDestination(new ArrayDnsResolver([], []));

        $this->expectException(InvalidArgumentException::class);
        $destination->validate('https://localhost/hooks');
    }

    public function test_local_tld_is_rejected(): void
    {
        $destination = new OutboundDestination(new ArrayDnsResolver([], []));

        $this->expectException(InvalidArgumentException::class);
        $destination->validate('https://printer.local/hooks');
    }

    public function test_public_hostname_whose_lookup_is_private_is_rejected(): void
    {
        $resolver = new ArrayDnsResolver([
            'intranet.example.test' => ['10.0.0.1'],
        ], []);
        $destination = new OutboundDestination($resolver);

        $this->expectException(InvalidArgumentException::class);
        $destination->validate('https://intranet.example.test/hooks');
    }

    public function test_dns_rebinding_is_rejected_on_the_second_lookup(): void
    {
        $resolver = new ArrayDnsResolver([
            'rebind.example.test' => ['93.184.216.34'],
        ], []);
        $destination = new OutboundDestination($resolver);

        $pin = $destination->validate('https://rebind.example.test/hooks');
        $this->assertSame('93.184.216.34', $pin->ip);

        $resolver->set('rebind.example.test', ['10.0.0.1']);

        $this->expectException(InvalidArgumentException::class);
        $destination->validate('https://rebind.example.test/hooks');
    }

    public function test_public_https_host_is_allowed_and_pinned(): void
    {
        $resolver = new ArrayDnsResolver([
            'hooks.example.test' => ['93.184.216.34'],
        ], []);
        $destination = new OutboundDestination($resolver);

        $pin = $destination->validate('https://hooks.example.test/approvedesk');

        $this->assertSame('hooks.example.test', $pin->host);
        $this->assertSame(443, $pin->port);
        $this->assertSame('93.184.216.34', $pin->ip);
        $this->assertSame('https://hooks.example.test/approvedesk', $pin->url);
        $this->assertStringContainsString('hooks.example.test:443:93.184.216.34', $pin->curlResolve());
    }

    public function test_userinfo_and_nonstandard_ports_are_rejected(): void
    {
        $destination = new OutboundDestination(new ArrayDnsResolver([
            'hooks.example.test' => ['93.184.216.34'],
        ], []));

        try {
            $destination->validate('https://user:pass@hooks.example.test/hooks');
            $this->fail('Expected userinfo to be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->expectException(InvalidArgumentException::class);
        $destination->validate('https://hooks.example.test:8080/hooks');
    }

    public function test_http_scheme_is_rejected(): void
    {
        $destination = new OutboundDestination(new ArrayDnsResolver([], []));

        $this->expectException(InvalidArgumentException::class);
        $destination->validate('http://hooks.example.test/hooks');
    }

    public function test_private_s3_endpoint_is_allowed_when_opted_in(): void
    {
        $destination = new OutboundDestination(new ArrayDnsResolver([
            'minio.internal.test' => ['10.0.0.5'],
        ], []));

        $pin = $destination->validate('https://minio.internal.test:9000', [
            'allow_private' => true,
            'allow_nonstandard_port' => true,
        ]);

        $this->assertSame('10.0.0.5', $pin->ip);
        $this->assertSame(9000, $pin->port);
    }

    public function test_documentation_ipv4_203_0_113_10_is_rejected(): void
    {
        $destination = new OutboundDestination(new ArrayDnsResolver([], []));

        $this->expectException(InvalidArgumentException::class);
        $destination->validate('https://203.0.113.10/push');
    }

    public function test_documentation_range_hostname_lookup_is_rejected(): void
    {
        $resolver = new ArrayDnsResolver([
            'example.test' => ['203.0.113.10'],
        ], []);
        $destination = new OutboundDestination($resolver);

        $this->expectException(InvalidArgumentException::class);
        $destination->validate('https://example.test/push');
    }

    public function test_link_local_multicast_and_unique_local_addresses_are_rejected(): void
    {
        $destination = new OutboundDestination(new ArrayDnsResolver([], []));

        foreach ([
            'https://169.254.1.1/push',
            'https://224.0.0.1/push',
            'https://[fd00::1]/push',
            'https://[fe80::1]/push',
            'https://[::1]/push',
        ] as $url) {
            try {
                $destination->validate($url);
                $this->fail("Expected {$url} to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_special_use_ipv6_literals_are_rejected(): void
    {
        $destination = new OutboundDestination(new ArrayDnsResolver([], []));

        foreach ([
            '64:ff9b:1::1',
            'fec0::1',
            '2002:0a00:0001::',
            '3fff::1',
            '5f00::1',
            '::ffff:10.0.0.1',
        ] as $ip) {
            try {
                $destination->validate("https://[{$ip}]/push");
                $this->fail("Expected special-use IPv6 literal {$ip} to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_hostnames_resolving_to_special_use_ipv6_addresses_are_rejected(): void
    {
        foreach ([
            '64:ff9b:1::1',
            'fec0::1',
            '2002:0a00:0001::',
            '3fff::1',
            '5f00::1',
            '::ffff:10.0.0.1',
        ] as $index => $ip) {
            $host = "blocked-{$index}.example.test";
            $destination = new OutboundDestination(new ArrayDnsResolver([$host => [$ip]], []));

            try {
                $destination->validate("https://{$host}/push");
                $this->fail("Expected hostname resolving to special-use IPv6 {$ip} to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_legitimate_global_ipv6_literal_and_hostname_are_allowed(): void
    {
        $ip = '2606:4700:4700::1111';
        $destination = new OutboundDestination(new ArrayDnsResolver([
            'push.example.test' => [$ip],
        ], []));

        $literal = $destination->validate("https://[{$ip}]/push");
        $hostname = $destination->validate('https://push.example.test/push');

        $this->assertSame($ip, $literal->ip);
        $this->assertSame($ip, $hostname->ip);
    }
}
