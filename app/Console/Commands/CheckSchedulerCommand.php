<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\BackupSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class CheckSchedulerCommand extends Command
{
    protected $signature = 'ops:scheduler-check';

    protected $description = 'Check scheduler heartbeat freshness without running scheduled work';

    public function handle(): int
    {
        $raw = Setting::get('ops.scheduler_heartbeat_at');
        $heartbeat = null;

        if ($raw !== null && $raw !== '') {
            try {
                $heartbeat = CarbonImmutable::parse($raw);
            } catch (\Throwable) {
                $heartbeat = null;
            }
        }

        $this->line('Application: '.base_path());
        $this->line('PHP: '.PHP_BINARY.' '.PHP_VERSION);
        $this->line('Timezone: '.config('app.timezone', 'UTC'));
        $this->line('Database backup: '.BackupSettings::databaseAt());
        $this->line('Files backup: '.BackupSettings::filesAt());

        if ($heartbeat === null) {
            $this->error('Scheduler heartbeat: missing');

            return self::FAILURE;
        }

        $age = now()->diffInSeconds($heartbeat);
        $this->line('Last heartbeat: '.$heartbeat->toIso8601String().' ('.$age.' seconds ago)');

        if ($heartbeat->lessThan(now()->subMinutes(5))) {
            $this->error('Scheduler heartbeat: stale');

            return self::FAILURE;
        }

        $this->info('Scheduler heartbeat: healthy');

        return self::SUCCESS;
    }
}
