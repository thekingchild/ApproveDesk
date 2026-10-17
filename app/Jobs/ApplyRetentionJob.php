<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\Audit\RetentionPolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Retires the requests RetentionPolicy says are due, by soft-deleting them. Nothing
 * is purged: the request, its workflow history and its files stay stored as
 * audit evidence (RV-DATA-08).
 */
class ApplyRetentionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One copy queued or running at a time; the schedule's withoutOverlapping()
     * only guards dispatch, so copies piled up when the worker lagged (RV-OPS-02).
     * The lock lapses after this many seconds if a worker is killed mid-job.
     */
    public int $uniqueFor = 3600;

    /** Below the scheduled worker's --timeout=90 so the job, not the worker, is stopped (RV-PERF-16). */
    public int $timeout = 75;

    /** Stop taking new rows after this long; the rest are picked up on the next run (RV-PERF-16). */
    public const TIME_BUDGET_SECONDS = 55;

    public const CHUNK = 200;

    private float $deadline = 0.0;

    public function handle(RetentionPolicy $policy): void
    {
        $this->deadline = microtime(true) + self::TIME_BUDGET_SECONDS;

        $this->retire($policy->draftsDue());
        $this->retire($policy->finishedDue());
    }

    /**
     * Keyset pagination: each() paged by OFFSET while rows dropped out of the
     * result set as they were soft-deleted, so every other page was skipped
     * (RV-PERF-16). Each page is retired with one UPDATE; Submission has no
     * delete listeners to skip.
     *
     * @param  Builder<Submission>  $query
     */
    private function retire(Builder $query): void
    {
        $query->select('id')->chunkById(self::CHUNK, function ($page): bool {
            if (microtime(true) >= $this->deadline) {
                return false;
            }

            Submission::query()->whereKey($page->modelKeys())->delete();

            return true;
        });
    }
}
