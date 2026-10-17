<?php

namespace App\Services;

use App\Models\ApprovalReferenceCounter;
use App\Models\Submission;
use App\Support\ApprovalReferenceSchema;
use App\Support\RandomDigitGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReferenceNumberGenerator
{
    public function __construct(
        private readonly ApprovalReferenceSchema $schema,
        private readonly RandomDigitGenerator $randomDigits,
    ) {}

    public function next(): string
    {
        if ($this->schema->generationMode() === 'random') {
            return $this->generateRandom();
        }

        return $this->generateSequential();
    }

    private function generateSequential(): string
    {
        // One clock reading for the counter, the prefix and the formatted ID, so a
        // request made at the turn of a month or year cannot mix two periods (RV-BUG-38).
        $at = now();
        $scope = $this->schema->counterScope($at);
        $prefix = $this->schema->numericPrefix($at);
        $digitLength = $this->schema->digitLength();
        $maximum = (10 ** $digitLength) - 1;

        return DB::transaction(function () use ($scope, $prefix, $digitLength, $maximum, $at): string {
            ApprovalReferenceCounter::query()->insertOrIgnore([
                'scope' => $scope,
                'current_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = ApprovalReferenceCounter::query()
                ->where('scope', $scope)
                ->lockForUpdate()
                ->firstOrFail();

            $maxExisting = 0;
            if ($counter->current_value === 0) {
                $expectedLength = strlen($prefix) + $digitLength;
                $maxExisting = Submission::withTrashed()
                    ->where('reference_number', 'like', $prefix.'%')
                    ->pluck('reference_number')
                    ->filter(function (string $reference) use ($prefix, $expectedLength): bool {
                        if (strlen($reference) !== $expectedLength) {
                            return false;
                        }

                        return ctype_digit(substr($reference, strlen($prefix)));
                    })
                    ->map(fn (string $reference): int => (int) substr($reference, strlen($prefix)))
                    ->max() ?? 0;
            }

            $next = max((int) $counter->current_value, $maxExisting) + 1;

            // Numbers issued while random mode was on may sit ahead of the counter.
            // Skip them; otherwise the insert fails, rolls the counter back, and
            // every later request retries the same number (RV-BUG-14).
            while ($next <= $maximum && $this->isTaken($this->schema->format($next, $at))) {
                $next++;
            }

            if ($next > $maximum) {
                throw new RuntimeException('The sequential approval ID range for this format is exhausted.');
            }

            $counter->update(['current_value' => $next]);

            return $this->schema->format($next, $at);
        }, 3);
    }

    private function isTaken(string $reference): bool
    {
        return Submission::withTrashed()->where('reference_number', $reference)->exists()
            || ApprovalReferenceCounter::query()->where('scope', 'r:'.$reference)->exists();
    }

    private function generateRandom(): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $reference = $this->schema->format($this->randomDigits->next($this->schema->digitLength()));

            if ($this->isTaken($reference)) {
                continue;
            }

            $reserved = ApprovalReferenceCounter::query()->insertOrIgnore([
                'scope' => 'r:'.$reference,
                'current_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($reserved > 0) {
                return $reference;
            }
        }

        throw new RuntimeException('Unable to generate a unique random approval ID.');
    }
}
