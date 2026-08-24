<?php

namespace App\Console\Commands;

use App\Actions\Operations\VerifyBackupAction;
use Illuminate\Console\Command;

class VerifyBackupCommand extends Command
{
    protected $signature = 'ops:backup-verify {path? : Optional SQLite file or SQL dump}';

    protected $description = 'Verify the live database or a backup file has the required records tables.';

    public function handle(VerifyBackupAction $verify): int
    {
        $path = $this->argument('path');
        $result = $verify->execute(null, is_string($path) && $path !== '' ? $path : null);

        if (! $result->ok) {
            foreach ($result->issues as $issue) {
                $this->error($issue);
            }

            return self::FAILURE;
        }

        $this->info('Backup verification passed.');

        return self::SUCCESS;
    }
}
