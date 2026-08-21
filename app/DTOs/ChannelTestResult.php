<?php

namespace App\DTOs;

final readonly class ChannelTestResult
{
    public function __construct(
        public bool $ok,
        public string $message,
    ) {}
}
