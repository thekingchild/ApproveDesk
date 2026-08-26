<?php

namespace App\Console\Commands;

use App\Actions\Backups\CreateDatabaseBackupAction;
use App\Enums\BackupSnapshotKind;
use App\Support\BackupSettings;
use App\Support\ScheduledBackupHealth;
use Illuminate\Console\Command;

class CreateDatabaseBackupCommand extends Command
{
    protected $signature = 'backups:database';

    protected $description = 'Create a database snapshot and copy it to enabled destinations';

    public function handle(CreateDatabaseBackupAction $action, ScheduledBackupHealth $health): int
    {
        if (! BackupSettings::databaseEnabled()) {
            $this->info('Database backups are disabled.');

            return self::SUCCESS;
        }

        try {
            $snapshot = $action->execute();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $health->record(BackupSnapshotKind::Database, $snapshot);
        $this->info('Database snapshot '.$snapshot->id.' stored ('.$snapshot->bytes.' bytes).');

        return self::SUCCESS;
    }
}
