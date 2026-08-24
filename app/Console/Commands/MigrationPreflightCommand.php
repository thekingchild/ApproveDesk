<?php

namespace App\Console\Commands;

use App\Services\Operations\MigrationPreflight;
use Illuminate\Console\Command;

class MigrationPreflightCommand extends Command
{
    protected $signature = 'migrate:preflight';

    protected $description = 'Check pending migrations for drop/rename operations that are not zero-downtime compatible.';

    public function handle(MigrationPreflight $preflight): int
    {
        $flagged = $preflight->inspectPending();

        if ($flagged === []) {
            $this->info('Pending migrations look additive.');

            return self::SUCCESS;
        }

        foreach ($flagged as $file => $flags) {
            $this->error($file.': '.implode(', ', $flags));
        }

        return self::FAILURE;
    }
}
