<?php

namespace App\Console\Commands;

use App\Actions\Backups\CreateDatabaseBackupAction;
use App\Support\BackupSettings;
use Illuminate\Console\Command;

class CreateDatabaseBackupCommand extends Command
{
    protected $signature = 'backups:database';

    protected $description = 'Create a database snapshot and copy it to enabled destinations';

    public function handle(CreateDatabaseBackupAction $action): int
    {
        if (! BackupSettings::databaseEnabled()) {
            $this->info('Database backups are disabled.');

            return self::SUCCESS;
        }

        $snapshot = $action->execute();
        $this->info('Database snapshot '.$snapshot->id.' stored ('.$snapshot->bytes.' bytes).');

        return self::SUCCESS;
    }
}
