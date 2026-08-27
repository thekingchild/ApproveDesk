<?php

namespace App\Support;

use InvalidArgumentException;

final class OutboundDestination
{
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

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
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

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(__('The destination cannot use that port.'));
        }

        if (! $allowPrivate && $this->isBlockedHostname($host)) {
            throw new InvalidArgumentException(__('The destination cannot target a private or local address.'));
        }

        if ($allowPrivate && ($host === '' || str_contains($host, ' ') || str_contains($host, "\0"))) {
            throw new InvalidArgumentException(__('The destination cannot target a private or local address.'));
        }

        if ($this->looksLikeIp($host)) {
            if (! $allowPrivate && ! $this->isPublicIp($host)) {
                throw new InvalidArgumentException(__('The destination cannot target a private or local address.'));
            }

            if ($allowPrivate && ! $this->isValidIp($host)) {
                throw new InvalidArgumentException(__('The destination cannot target a private or local address.'));
            }

            return new OutboundPin($url, $host, $port, $host, [$host]);
        }

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new InvalidArgumentException(__('The destination could not be resolved.'));
        }

        foreach ($ips as $ip) {
            if (! $this->isValidIp($ip)) {
                throw new InvalidArgumentException(__('The destination cannot target a private or local address.'));
            }

            if (! $allowPrivate && ! $this->isPublicIp($ip)) {
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

        if (function_exists('idn_to_ascii') && $host !== '' && ! $this->looksLikeIp($host)) {
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

        if (ctype_digit($host)) {
            return true;
        }

        return false;
    }

    private function looksLikeIp(string $host): bool
    {
        return $this->isValidIp($host);
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function isPublicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            return $this->isPublicIp(substr($ip, 7));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($this->isCgnat($ip)) {
                return false;
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return false;
    }

    private function isCgnat(string $ip): bool
    {
        $long = ip2long($ip);

        return $long !== false && ($long & 0xFFC00000) === 0x64400000;
    }
}
