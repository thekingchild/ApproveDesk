<?php

namespace Tests\Unit\Support;

use App\Support\LocalTime;
use Tests\TestCase;

/**
 * RV-BUG-03: browser-local times are interpreted in the browser's timezone.
 */
class LocalTimeTest extends TestCase
{
    public function test_browser_local_time_is_converted_to_the_application_timezone(): void
    {
        config(['app.timezone' => 'UTC']);

        $parsed = LocalTime::parse('2026-10-16T17:00', 'Africa/Lagos');

        $this->assertSame('2026-10-16 16:00:00', $parsed->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $parsed->timezoneName);
    }

    public function test_unknown_or_missing_timezone_falls_back_to_the_application_timezone(): void
    {
        config(['app.timezone' => 'UTC']);

        $this->assertSame('2026-10-16 17:00:00', LocalTime::parse('2026-10-16T17:00', 'Mars/Olympus')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-16 17:00:00', LocalTime::parse('2026-10-16T17:00', null)->format('Y-m-d H:i:s'));
    }
}
