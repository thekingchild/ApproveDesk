<?php

namespace App\DTOs\Integrations;

final readonly class ConnectorHealth
{
    public function __construct(
        public bool $ok,
        public string $message,
    ) {}
}
