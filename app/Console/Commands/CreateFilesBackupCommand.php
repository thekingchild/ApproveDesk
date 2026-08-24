<?php

namespace App\Console\Commands;

use App\Actions\Backups\CreateFilesBackupAction;
use App\Support\BackupSettings;
use Illuminate\Console\Command;

class CreateFilesBackupCommand extends Command
{
    protected $signature = 'backups:files';

    protected $description = 'Create a files snapshot and copy it to enabled destinations';

    public function handle(CreateFilesBackupAction $action): int
    {
        if (! BackupSettings::filesEnabled()) {
            $this->info('File backups are disabled.');

            return self::SUCCESS;
        }

        $snapshot = $action->execute();
        $this->info('Files snapshot '.$snapshot->id.' stored ('.$snapshot->bytes.' bytes).');

        return self::SUCCESS;
    }
}
