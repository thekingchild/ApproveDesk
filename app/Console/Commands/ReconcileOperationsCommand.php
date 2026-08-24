<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationsReconciler;
use Illuminate\Console\Command;

class ReconcileOperationsCommand extends Command
{
    protected $signature = 'ops:reconcile';

    protected $description = 'Report workflow and delivery inconsistencies without changing records.';

    public function handle(OperationsReconciler $reconciler): int
    {
        $report = $reconciler->run();

        if ($report->ok()) {
            $this->info('Reconciliation found no inconsistencies.');

            return self::SUCCESS;
        }

        foreach ($report->findings as $finding) {
            $this->warn($finding);
        }

        return self::FAILURE;
    }
}
