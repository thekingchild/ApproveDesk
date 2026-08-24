<?php

namespace App\Actions\Operations;

use App\DTOs\Operations\BackupVerificationResult;
use App\Models\OpsBackupVerification;
use App\Models\User;
use App\Services\Operations\BackupVerifier;

final class VerifyBackupAction
{
    public function __construct(private readonly BackupVerifier $verifier) {}

    public function execute(?User $actor, ?string $path = null): BackupVerificationResult
    {
        $result = $path === null
            ? $this->verifier->verifyCurrent()
            : $this->verifier->verifyPath($path);

        OpsBackupVerification::query()->create([
            'verified_at' => now(),
            'source' => $result->source,
            'path' => $result->path === null ? null : basename($result->path),
            'ok' => $result->ok,
            'table_counts' => $result->tableCounts,
            'issues' => $result->issues,
            'verified_by' => $actor?->id,
        ]);

        return $result;
    }
}
