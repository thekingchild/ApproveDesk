<?php

namespace App\Support;

use App\Contracts\DnsResolver;

final class PhpDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $host = rtrim($host, '.');
        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                $ips = array_merge($ips, $ipv4);
            }
        }

        return array_values(array_unique($ips));
    }
}
