<?php

namespace App\Console\Commands;

use App\Actions\Backups\CreateFilesBackupAction;
use App\Enums\BackupSnapshotKind;
use App\Services\Backups\ScheduledBackupHealth;
use App\Support\BackupSettings;
use Illuminate\Console\Command;

class CreateFilesBackupCommand extends Command
{
    protected $signature = 'backups:files';

    protected $description = 'Create a files snapshot and copy it to enabled destinations';

    public function handle(CreateFilesBackupAction $action, ScheduledBackupHealth $health): int
    {
        if (! BackupSettings::filesEnabled()) {
            $this->info('File backups are disabled.');

            return self::SUCCESS;
        }

        try {
            $snapshot = $action->execute();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $health->record(BackupSnapshotKind::Files, $snapshot);
        $this->info('Files snapshot '.$snapshot->id.' stored ('.$snapshot->bytes.' bytes).');

        return self::SUCCESS;
    }
}
