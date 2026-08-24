<?php

namespace App\Console\Commands;

use App\Services\Operations\RestoreDrill;
use Illuminate\Console\Command;

class RestoreDrillCommand extends Command
{
    protected $signature = 'ops:restore-drill {path? : Optional backup file to verify instead of a live copy}';

    protected $description = 'Run a restore drill against a copy. Never writes back onto the live database.';

    public function handle(RestoreDrill $drill): int
    {
        $path = $this->argument('path');
        $result = $drill->run(null, is_string($path) && $path !== '' ? $path : null);

        if (! $result->ok) {
            foreach ($result->issues as $issue) {
                $this->error($issue);
            }

            return self::FAILURE;
        }

        $this->info('Restore drill succeeded. Workflow history was not changed.');

        return self::SUCCESS;
    }
}
