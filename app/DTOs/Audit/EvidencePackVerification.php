<?php

namespace App\DTOs\Audit;

final readonly class EvidencePackVerification
{
    /**
     * $ok means the files are intact and nothing was added. $legacy marks a
     * pack from before signed manifests: its file hashes check out, but its
     * metadata (date, request id) is unsigned, so it is not "verified" (RV-SEC-21).
     */
    public function __construct(
        public bool $ok,
        public string $error = '',
        public bool $legacy = false,
    ) {}

    public function verified(): bool
    {
        return $this->ok && ! $this->legacy;
    }

    public function message(): string
    {
        if (! $this->ok) {
            return $this->error;
        }

        return $this->legacy
            ? 'Unsigned (legacy): the files match their signed hashes and nothing was added, but this older pack does not sign its date or request number. Download a fresh pack for a fully verified copy.'
            : 'Verified: this pack is intact and signed by this ApproveDesk.';
    }
}
