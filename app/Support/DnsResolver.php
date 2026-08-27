<?php

namespace App\Support;

interface DnsResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
