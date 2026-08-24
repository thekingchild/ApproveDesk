<?php

namespace App\Console\Commands;

use App\Actions\Operations\RecordSchedulerHeartbeatAction;
use Illuminate\Console\Command;

class RecordSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Record the scheduler heartbeat and a queue health snapshot.';

    public function handle(RecordSchedulerHeartbeatAction $heartbeat): int
    {
        $heartbeat->execute();
        $this->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
