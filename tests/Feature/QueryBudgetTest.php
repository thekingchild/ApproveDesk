<?php

namespace Tests\Feature;

use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_administrator_dashboard_stays_within_its_query_budget(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create()->assignRole('approver');
        $submitter = User::factory()->create()->assignRole('submitter');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();

        Submission::factory()->count(5)->for($submitter, 'submitter')->create()
            ->each(fn (Submission $submission) => app(WorkflowEngine::class)->initialize($submission));

        $queries = $this->countQueries(fn () => $this->actingAs($admin)->get(route('dashboard'))->assertOk());

        $this->assertLessThanOrEqual(40, $queries, "Administrator dashboard exceeded its 40-query budget ({$queries}).");
    }

    public function test_approval_inbox_stays_within_its_query_budget(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        $submitter = User::factory()->create()->assignRole('submitter');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();

        Submission::factory()->count(5)->for($submitter, 'submitter')->create()
            ->each(fn (Submission $submission) => app(WorkflowEngine::class)->initialize($submission));

        $queries = $this->countQueries(fn () => $this->actingAs($approver)->get(route('approvals.inbox'))->assertOk());

        $this->assertLessThanOrEqual(30, $queries, "Approval inbox exceeded its 30-query budget ({$queries}).");
    }

    public function test_submission_list_stays_within_its_query_budget(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        Submission::factory()->count(20)->for($submitter, 'submitter')->create();

        $queries = $this->countQueries(fn () => $this->actingAs($submitter)->get(route('submissions.index'))->assertOk());

        $this->assertLessThanOrEqual(15, $queries, "Submission list exceeded its 15-query budget ({$queries}).");
    }

    private function countQueries(callable $request): int
    {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (! str_starts_with($query->sql, 'select * from "sessions"')) {
                $queries++;
            }
        });

        $request();

        return $queries;
    }
}
