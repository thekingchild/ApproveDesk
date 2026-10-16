<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Helpers for backup, verify, and promote, which run inside a web request
 * on shared hosting (RV-OPS-03).
 *
 * prepare() lifts PHP's own time limit and keeps going if the browser
 * disconnects. onFatal() runs a recovery callback when the request dies
 * from a fatal error (out of memory, a host time limit), which skips
 * finally blocks; disarm() it once the protected work has finished.
 */
final class LongRunningOperation
{
    private bool $armed = true;

    private function __construct(private readonly Closure $recover) {}

    public static function prepare(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        ignore_user_abort(true);
    }

    public static function onFatal(Closure $recover): self
    {
        $guard = new self($recover);
        register_shutdown_function($guard->handleShutdown(...));

        return $guard;
    }

    public function disarm(): void
    {
        $this->armed = false;
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if (! $this->armed || $error === null || ! in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        $this->armed = false;
        // Leave room to recover after an out-of-memory error.
        @ini_set('memory_limit', '-1');

        try {
            ($this->recover)();
        } catch (Throwable) {
            // Nothing more can be done in a dying request.
        }
    }
}
