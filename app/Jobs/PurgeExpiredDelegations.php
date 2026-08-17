<?php

namespace App\Jobs;

use App\Models\Delegation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PurgeExpiredDelegations implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Delegation::query()->where('expires_at', '<=', now())->delete();
    }
}
