<?php

namespace App\Console\Commands;

use App\Models\Submission;
use App\Models\WorkflowAction;
use App\Support\PerformanceBudgets;
use Illuminate\Console\Command;

class BudgetReportCommand extends Command
{
    protected $signature = 'ops:budget-report';

    protected $description = 'Compare current record counts with documented performance budgets.';

    public function handle(): int
    {
        $rows = [
            ['submissions', Submission::query()->count(), PerformanceBudgets::SUBMISSIONS],
            ['workflow_actions', WorkflowAction::query()->count(), PerformanceBudgets::WORKFLOW_ACTIONS],
        ];

        $this->table(['Metric', 'Current', 'Budget'], $rows);

        return self::SUCCESS;
    }
}
