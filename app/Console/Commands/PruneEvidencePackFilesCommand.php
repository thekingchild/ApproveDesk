<?php

namespace App\Console\Commands;

use App\Actions\Audit\PruneEvidencePackFilesAction;
use Illuminate\Console\Command;

class PruneEvidencePackFilesCommand extends Command
{
    protected $signature = 'evidence:prune-files {--days='.PruneEvidencePackFilesAction::KEEP_DAYS.' : Keep zips generated within this many days}';

    protected $description = 'Delete generated evidence-pack zip files older than the keep window (the pack records stay).';

    public function handle(PruneEvidencePackFilesAction $prune): int
    {
        $deleted = $prune->execute((int) $this->option('days'));

        $this->info("Deleted {$deleted} evidence pack file(s).");

        return self::SUCCESS;
    }
}
