<?php

namespace App\Services\Operations;

use App\DTOs\Operations\BackupVerificationResult;
use App\Models\OpsBackupVerification;
use App\Models\User;
use App\Models\WorkflowAction;
use RuntimeException;

final class RestoreDrill
{
    public function __construct(private readonly BackupVerifier $verifier) {}

    public function run(?User $actor = null, ?string $path = null): BackupVerificationResult
    {
        $actionsBefore = WorkflowAction::query()->count();

        $result = $path === null
            ? $this->drillFromLiveCopy()
            : $this->verifier->verifyPath($path);

        if (WorkflowAction::query()->count() !== $actionsBefore) {
            throw new RuntimeException('Restore drill must not change workflow history.');
        }

        $this->store($result, $actor);

        return $result;
    }

    private function drillFromLiveCopy(): BackupVerificationResult
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (config('database.default') === 'sqlite' && is_file($database)) {
            $copy = storage_path('app/ops-restore-drill-'.uniqid('', true).'.sqlite');
            copy($database, $copy);

            try {
                $copyResult = $this->verifier->verifyPath($copy);
                $live = $this->verifier->verifyCurrent();

                $issues = $copyResult->issues;
                foreach ($live->tableCounts as $table => $count) {
                    if (($copyResult->tableCounts[$table] ?? -1) !== $count) {
                        $issues[] = 'Copied backup count does not match the live '.$table.' table.';
                    }
                }

                return new BackupVerificationResult($issues === [], 'file', $copy, $copyResult->tableCounts, $issues);
            } finally {
                if (is_file($copy)) {
                    unlink($copy);
                }
            }
        }

        return $this->verifier->verifyCurrent();
    }

    private function store(BackupVerificationResult $result, ?User $actor): void
    {
        OpsBackupVerification::query()->create([
            'verified_at' => now(),
            'source' => $result->source,
            'path' => $result->path === null ? null : basename($result->path),
            'ok' => $result->ok,
            'table_counts' => $result->tableCounts,
            'issues' => $result->issues,
            'verified_by' => $actor?->id,
        ]);
    }
}
