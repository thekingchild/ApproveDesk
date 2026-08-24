<?php

namespace App\Console\Commands;

use App\Actions\Backups\RetainBackupSnapshotsAction;
use Illuminate\Console\Command;

class RetainBackupsCommand extends Command
{
    protected $signature = 'backups:retain';

    protected $description = 'Delete expired backup snapshots while keeping the last success of each kind';

    public function handle(RetainBackupSnapshotsAction $action): int
    {
        $deleted = $action->execute();
        $this->info('Removed '.$deleted.' expired snapshot(s).');

        return self::SUCCESS;
    }
}
