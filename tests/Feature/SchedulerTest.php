<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    public function test_database_queue_is_drained_by_the_shared_hosting_schedule(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command)
            ->filter();

        $this->assertTrue(
            $commands->contains(fn (string $command) => str_contains($command, 'queue:work')
                && str_contains($command, '--stop-when-empty')),
            'The scheduler must drain and exit the database queue for shared hosting.',
        );
    }
}
