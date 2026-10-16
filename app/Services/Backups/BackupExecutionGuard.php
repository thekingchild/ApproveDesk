<?php

namespace App\Services\Backups;

use App\Support\LongRunningOperation;
use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class BackupExecutionGuard
{
    public const LOCK_NAME = 'approvedesk:backups:execution';

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(Closure $callback): mixed
    {
        LongRunningOperation::prepare();
        $lock = Cache::lock(self::LOCK_NAME, 7200);

        if (! $lock->get()) {
            throw new InvalidArgumentException('Another backup is already running. Try again after it finishes.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
