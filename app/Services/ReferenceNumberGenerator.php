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
        $scope = $this->schema->counterScope();
        $prefix = $this->schema->numericPrefix();
        $digitLength = $this->schema->digitLength();
        $maximum = (10 ** $digitLength) - 1;

        return DB::transaction(function () use ($scope, $prefix, $digitLength, $maximum): string {
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
            if ($next > $maximum) {
                throw new RuntimeException('The sequential approval ID range for this format is exhausted.');
            }

            $counter->update(['current_value' => $next]);

            return $this->schema->format($next);
        }, 3);
    }

    private function generateRandom(): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $reference = $this->schema->format($this->randomDigits->next($this->schema->digitLength()));

            if (Submission::withTrashed()->where('reference_number', $reference)->exists()) {
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
