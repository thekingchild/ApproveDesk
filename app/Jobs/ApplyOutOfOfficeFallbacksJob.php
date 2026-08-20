<?php

namespace App\Jobs;

use App\Services\Collaboration\OutOfOfficeFallback;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ApplyOutOfOfficeFallbacksJob implements ShouldQueue
{
    use Queueable;

    public function handle(OutOfOfficeFallback $fallbacks): void
    {
        $fallbacks->applyActiveSchedules();
    }
}
