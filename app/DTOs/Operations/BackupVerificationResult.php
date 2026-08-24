<?php

namespace App\DTOs\Operations;

final readonly class BackupVerificationResult
{
    /**
     * @param  array<string, int>  $tableCounts
     * @param  list<string>  $issues
     */
    public function __construct(
        public bool $ok,
        public string $source,
        public ?string $path,
        public array $tableCounts,
        public array $issues,
    ) {}
}
