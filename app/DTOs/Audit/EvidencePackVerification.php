<?php

namespace App\DTOs\Audit;

final readonly class EvidencePackVerification
{
    public function __construct(
        public bool $ok,
        public string $error = '',
    ) {}
}
