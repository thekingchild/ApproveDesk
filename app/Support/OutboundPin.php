<?php

namespace App\Support;

use RuntimeException;

final readonly class OutboundPin
{
    /**
     * @param  list<string>  $ips
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ip,
        public array $ips,
    ) {}

    public function curlResolve(): string
    {
        return self::resolveEntry($this->host, $this->port, [$this->ip]);
    }

    /**
     * @param  self|list<self>  $pins
     * @return list<string>
     */
    public static function curlResolveEntries(self|array $pins): array
    {
        $pins = $pins instanceof self ? [$pins] : $pins;
        /** @var array<string, array{host: string, port: int, ips: list<string>}> $groups */
        $groups = [];

        foreach ($pins as $pin) {
            $key = $pin->host."\0".$pin->port;
            $groups[$key] ??= [
                'host' => $pin->host,
                'port' => $pin->port,
                'ips' => [],
            ];

            foreach ($pin->ips as $ip) {
                if (! in_array($ip, $groups[$key]['ips'], true)) {
                    $groups[$key]['ips'][] = $ip;
                }
            }
        }

        return array_values(array_map(static function (array $group): string {
            if ($group['ips'] === []) {
                throw new RuntimeException('A pinned destination must have at least one validated address.');
            }

            return self::resolveEntry($group['host'], $group['port'], $group['ips']);
        }, $groups));
    }

    /**
     * @return array<string, mixed>
     */
    public function httpOptions(): array
    {
        $options = [
            'verify' => true,
            'allow_redirects' => false,
        ];

        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [
                CURLOPT_RESOLVE => self::curlResolveEntries($this),
            ];
        }

        return $options;
    }

    /**
     * @param  non-empty-list<string>  $ips
     */
    private static function resolveEntry(string $host, int $port, array $ips): string
    {
        $host = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$host.']' : $host;
        $addresses = array_map(
            static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip,
            $ips,
        );

        return sprintf('%s:%d:%s', $host, $port, implode(',', $addresses));
    }
}
