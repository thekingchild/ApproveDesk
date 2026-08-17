<?php

namespace App\DTOs;

final readonly class WebhookEndpointData
{
    /** @param array<int, string> $events */
    public function __construct(
        public string $name,
        public string $url,
        public array $events,
        public bool $isActive,
    ) {}
}
