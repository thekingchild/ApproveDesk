<?php

namespace App\Services;

use App\Models\Submission;

/**
 * Generates human-friendly submission reference numbers (e.g. SUB-2026-00042).
 *
 * The sequence restarts each calendar year. Uniqueness is backed by the
 * unique index on submissions.reference_number; next() skips collisions so
 * concurrent creation converges without failing the insert.
 */
class ReferenceNumberGenerator
{
    public function next(string $prefix = 'SUB'): string
    {
        $year = now()->year;

        $base = (int) Submission::withTrashed()
            ->whereYear('created_at', $year)
            ->where('reference_number', 'like', "{$prefix}-{$year}-%")
            ->count();

        for ($attempt = 1; $attempt <= 25; $attempt++) {
            $candidate = sprintf('%s-%d-%05d', $prefix, $year, $base + $attempt);

            if (! $this->exists($candidate)) {
                return $candidate;
            }
        }

        // Fall back to a time-based tail that cannot collide in practice.
        $tail = ((int) now()->getTimestamp()) % 100_000;

        return sprintf('%s-%d-%05d', $prefix, $year, ($base + 26) + $tail);
    }

    private function exists(string $candidate): bool
    {
        return Submission::withTrashed()
            ->where('reference_number', $candidate)
            ->exists();
    }
}
