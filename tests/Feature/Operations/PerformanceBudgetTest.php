<?php

namespace Tests\Feature\Operations;

use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Operations\MigrationPreflight;
use App\Services\WorkflowEngine;
use App\Support\PerformanceBudgets;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PerformanceBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_documented_budgets_match_the_phase_targets(): void
    {
        $budgets = PerformanceBudgets::documented();

        $this->assertSame(100_000, $budgets['submissions']);
        $this->assertSame(1_000_000, $budgets['workflow_actions']);
        $this->assertSame(52_428_800, $budgets['large_attachment_bytes']);
        $this->assertSame(50, $budgets['approver_fan_out']);
        $this->artisan('ops:budget-report')->assertSuccessful();
    }

    public function test_operations_page_stays_within_its_query_budget(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $queries = $this->countQueries(fn () => $this->actingAs($admin)->get(route('admin.operations.index'))->assertOk());

        $this->assertLessThanOrEqual(
            PerformanceBudgets::OPERATIONS_QUERIES,
            $queries,
            "Operations page exceeded its query budget ({$queries}).",
        );
    }

    public function test_submission_list_query_count_does_not_grow_linearly(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        Submission::factory()->count(8)->for($submitter, 'submitter')->create();
        $small = $this->countQueries(fn () => $this->actingAs($submitter)->get(route('submissions.index'))->assertOk());

        Submission::factory()->count(32)->for($submitter, 'submitter')->create();
        $large = $this->countQueries(fn () => $this->actingAs($submitter)->get(route('submissions.index'))->assertOk());

        $this->assertLessThanOrEqual(
            $small + PerformanceBudgets::LIST_GROWTH_SLACK,
            $large,
            "Submission list queries grew from {$small} to {$large} when rows increased 5x.",
        );
    }

    public function test_hot_path_indexes_exist(): void
    {
        $this->assertTrue($this->indexCovers('workflow_actions', 'instance_id'));
        $this->assertTrue($this->indexCovers('jobs', 'queue'));
        $this->assertTrue($this->indexCovers('failed_jobs', 'uuid') || $this->uniqueCovers('failed_jobs', 'uuid'));
    }

    public function test_high_fan_out_initialize_stays_bounded(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approvers = User::factory()->count(10)->create()->each->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential($approvers->all())->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();

        $queries = $this->countQueries(fn () => app(WorkflowEngine::class)->initialize($submission));

        $this->assertLessThanOrEqual(80, $queries, "Initializing a 10-approver chain used {$queries} queries.");
    }

    public function test_preflight_flags_destructive_sql_and_allows_additive(): void
    {
        $preflight = app(MigrationPreflight::class);

        $this->assertContains('destructive_drop', $preflight->inspect('$table->dropColumn("name");'));
        $this->assertContains('rename', $preflight->inspect('$table->renameColumn("old", "new");'));
        $this->assertSame([], $preflight->inspect('Schema::create("ops_snapshots", function (Blueprint $table) { $table->id(); });'));
        $this->assertSame([], $preflight->inspectPending());
        $this->artisan('migrate:preflight')->assertSuccessful();
    }

    public function test_phase_93_migration_is_additive(): void
    {
        $path = database_path('migrations/2026_08_24_150000_create_phase_93_operations_tables.php');
        $this->assertFileExists($path);
        $this->assertSame([], app(MigrationPreflight::class)->inspect((string) file_get_contents($path)));
    }

    private function countQueries(callable $work): int
    {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (! str_starts_with($query->sql, 'select * from "sessions"')) {
                $queries++;
            }
        });
        $work();

        return $queries;
    }

    private function indexCovers(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (in_array($column, $index['columns'], true)) {
                return true;
            }
        }

        return false;
    }

    private function uniqueCovers(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['unique'] ?? false) && in_array($column, $index['columns'], true)) {
                return true;
            }
        }

        return false;
    }
}
