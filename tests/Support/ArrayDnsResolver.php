<?php

namespace Tests\Support;

use App\Contracts\DnsResolver;

final class ArrayDnsResolver implements DnsResolver
{
    /**
     * @param  array<string, list<string>>  $hosts
     * @param  list<string>  $fallback
     */
    public function __construct(
        private array $hosts = [],
        private array $fallback = ['93.184.216.34'],
    ) {}

    public function resolve(string $host): array
    {
        $key = strtolower(rtrim($host, '.'));

        if (array_key_exists($key, $this->hosts)) {
            return $this->hosts[$key];
        }

        return $this->fallback;
    }

    /**
     * @param  list<string>  $ips
     */
    public function set(string $host, array $ips): void
    {
        $this->hosts[strtolower(rtrim($host, '.'))] = $ips;
    }
}
