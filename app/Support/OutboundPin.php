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
        $ip = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return sprintf('%s:%d:%s', $this->host, $this->port, $ip);
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
                CURLOPT_RESOLVE => [$this->curlResolve()],
            ];
        }

        return $options;
    }
}
