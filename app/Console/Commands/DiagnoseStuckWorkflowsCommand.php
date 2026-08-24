<?php

namespace App\Console\Commands;

use App\Services\Operations\StuckWorkflowDiagnostics;
use Illuminate\Console\Command;

class DiagnoseStuckWorkflowsCommand extends Command
{
    protected $signature = 'ops:diagnose-stuck';

    protected $description = 'List active workflows that have no current step or have waited past the stuck threshold.';

    public function handle(StuckWorkflowDiagnostics $diagnostics): int
    {
        $stuck = $diagnostics->find();

        if ($stuck->isEmpty()) {
            $this->info('No stuck workflows.');

            return self::SUCCESS;
        }

        $this->table(
            ['Instance', 'Reference', 'Reason', 'Step started'],
            $stuck->map(fn ($item): array => [
                $item->instanceId,
                $item->reference,
                $item->reason,
                $item->stepStartedAt?->toDateTimeString() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
