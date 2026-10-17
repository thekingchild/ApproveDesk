<?php

namespace App\Services\Backups;

use App\Support\LongRunningOperation;
use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * One backup, verify-for-promote, or promote at a time (RV-OPS-06).
 */
final class BackupExecutionGuard
{
    public const LOCK_NAME = 'approvedesk:backups:execution';

    /** Re-entry from the same process (a promote taking its safety backup) reuses the held lock. */
    private static int $depth = 0;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(Closure $callback): mixed
    {
        LongRunningOperation::prepare();

        if (self::$depth > 0) {
            self::$depth++;
            try {
                return $callback();
            } finally {
                self::$depth--;
            }
        }

        $lock = Cache::lock(self::LOCK_NAME, 7200);

        if (! $lock->get()) {
            throw new InvalidArgumentException('Another backup is already running. Try again after it finishes.');
        }

        self::$depth = 1;
        try {
            return $callback();
        } finally {
            self::$depth = 0;
            $lock->release();
        }
    }
}
