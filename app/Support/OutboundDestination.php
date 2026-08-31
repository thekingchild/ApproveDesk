<?php

namespace App\Support;

use InvalidArgumentException;

final class OutboundDestination
{
    /** @var list<array{string, int}> */
    private const BLOCKED_IPV4_CIDRS = [
        ['0.0.0.0', 8],       // This network / unspecified
        ['10.0.0.0', 8],      // Private-use
        ['100.64.0.0', 10],    // Shared address space (CGNAT)
        ['127.0.0.0', 8],      // Loopback
        ['169.254.0.0', 16],   // Link-local
        ['172.16.0.0', 12],    // Private-use
        ['192.0.0.0', 24],     // IETF protocol assignments
        ['192.0.2.0', 24],     // Documentation (TEST-NET-1)
        ['192.88.99.0', 24],   // Deprecated 6to4 relay anycast
        ['192.168.0.0', 16],   // Private-use
        ['198.18.0.0', 15],    // Benchmarking
        ['198.51.100.0', 24],  // Documentation (TEST-NET-2)
        ['203.0.113.0', 24],   // Documentation (TEST-NET-3)
        ['224.0.0.0', 4],      // Multicast
        ['240.0.0.0', 4],      // Reserved / limited broadcast
    ];

    /** @var list<array{string, int}> */
    private const BLOCKED_IPV6_CIDRS = [
        ['::', 96],            // Unspecified, loopback, and IPv4-compatible space
        ['100::', 64],         // Discard-only
        ['2001:2::', 48],      // Benchmarking
        ['2001:db8::', 32],    // Documentation
        ['fc00::', 7],         // Unique-local
        ['fe80::', 10],        // Link-local
        ['ff00::', 8],         // Multicast
    ];

    public function __construct(private readonly DnsResolver $resolver) {}

    /**
     * @param  array{allow_private?: bool, allow_nonstandard_port?: bool}  $options
     */
    public function validate(string $url, array $options = []): OutboundPin
    {
        $allowPrivate = (bool) ($options['allow_private'] ?? false);
        $allowNonstandardPort = (bool) ($options['allow_nonstandard_port'] ?? false);

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(__('The destination must be a valid URL.'));
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new InvalidArgumentException(__('The destination must be a valid URL.'));
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new InvalidArgumentException(__('The destination must use HTTPS.'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(__('The destination cannot include a username or password.'));
        }

        $host = $this->normalizeHost((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;

        if ($port !== 443 && ! $allowNonstandardPort) {
            throw new InvalidArgumentException(__('The destination cannot use that port.'));
        }

        if (! $allowPrivate && $this->isBlockedHostname($host)) {
            throw new InvalidArgumentException(__('The destination cannot target a private or local address.'));
        }

        if ($allowPrivate && ($host === '' || str_contains($host, ' ') || str_contains($host, "\0"))) {
            throw new InvalidArgumentException(__('The destination cannot target a private or local address.'));
        }

        if ($this->isValidIp($host)) {
            if (! $allowPrivate && ! $this->isPublicIp($host)) {
                throw new InvalidArgumentException(__('The destination cannot target a private or local address.'));
            }

            return new OutboundPin($url, $host, $port, $host, [$host]);
        }

        $ips = array_values(array_unique($this->resolver->resolve($host)));
        if ($ips === []) {
            throw new InvalidArgumentException(__('The destination could not be resolved.'));
        }

        foreach ($ips as $ip) {
            if (! $this->isValidIp($ip) || (! $allowPrivate && ! $this->isPublicIp($ip))) {
                throw new InvalidArgumentException(__('The destination cannot target a private or local address.'));
            }
        }

        return new OutboundPin($url, $host, $port, $ips[0], $ips);
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = trim($host, '[]');
        $host = rtrim($host, '.');

        if (function_exists('idn_to_ascii') && $host !== '' && ! $this->isValidIp($host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }

    private function isBlockedHostname(string $host): bool
    {
        if ($host === '' || str_contains($host, ' ') || str_contains($host, "\0")) {
            return true;
        }

        if (in_array($host, ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'], true)) {
            return true;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            return true;
        }

        return ctype_digit($host);
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function isPublicIp(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $mapped = inet_ntop(substr($binary, 12));

            return is_string($mapped) && $this->isPublicIp($mapped);
        }

        $cidrs = strlen($binary) === 4 ? self::BLOCKED_IPV4_CIDRS : self::BLOCKED_IPV6_CIDRS;
        foreach ($cidrs as [$network, $prefix]) {
            if ($this->hasPrefix($binary, $network, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function hasPrefix(string $address, string $network, int $prefixLength): bool
    {
        $networkBinary = @inet_pton($network);
        if ($networkBinary === false || strlen($address) !== strlen($networkBinary)) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        if ($fullBytes > 0 && substr($address, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($address[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
