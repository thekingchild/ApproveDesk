<?php

namespace App\Support;

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
        return self::resolveEntry($this->host, $this->port, $this->ip);
    }

    /**
     * @param  self|list<self>  $pins
     * @return list<string>
     */
    public static function curlResolveEntries(self|array $pins): array
    {
        $pins = $pins instanceof self ? [$pins] : $pins;
        $entries = [];

        foreach ($pins as $pin) {
            foreach ($pin->ips as $ip) {
                $entries[] = self::resolveEntry($pin->host, $pin->port, $ip);
            }
        }

        return array_values(array_unique($entries));
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

    private static function resolveEntry(string $host, int $port, string $ip): string
    {
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        return sprintf('%s:%d:%s', $host, $port, $address);
    }
}
