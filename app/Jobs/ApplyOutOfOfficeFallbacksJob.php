<?php

namespace App\Jobs;

use App\Services\Collaboration\OutOfOfficeFallback;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ApplyOutOfOfficeFallbacksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One copy queued or running at a time; the schedule's withoutOverlapping()
     * only guards dispatch, so copies piled up when the worker lagged (RV-OPS-02).
     * The lock lapses after this many seconds if a worker is killed mid-job.
     */
    public int $uniqueFor = 900;

    public function handle(OutOfOfficeFallback $fallbacks): void
    {
        $fallbacks->applyActiveSchedules();
    }
}
