<?php

namespace App\DTOs\Operations;

use Carbon\CarbonInterface;

final readonly class DeadLetterItem
{
    public function __construct(
        public string $type,
        public string $id,
        public string $label,
        public ?string $error,
        public ?CarbonInterface $failedAt,
    ) {}
}
