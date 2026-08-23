<?php

namespace App\DTOs\Integrations;

final readonly class ConnectorResult
{
    public function __construct(
        public bool $ok,
        public ?int $status = null,
        public string $body = '',
        public string $error = '',
    ) {}
}
