<?php

namespace Tests\Feature\Operations;

use App\Models\OpsBackupVerification;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Services\Operations\BackupVerifier;
use App\Services\Operations\RestoreDrill;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_current_database_passes_backup_verify(): void
    {
        $this->artisan('ops:backup-verify')->assertSuccessful();
        $this->assertTrue(OpsBackupVerification::query()->latest('id')->first()?->ok);
    }

    public function test_sql_dump_missing_workflow_actions_fails(): void
    {
        $path = sys_get_temp_dir().'/ad-backup-missing-'.uniqid('', true).'.sql';
        file_put_contents($path, "CREATE TABLE users (id INTEGER);\nCREATE TABLE submissions (id INTEGER);\n");

        $this->artisan('ops:backup-verify', ['path' => $path])->assertFailed();
        unlink($path);
    }

    public function test_sqlite_file_with_required_tables_passes(): void
    {
        $path = $this->sqliteBackupWithRequiredTables();

        $this->artisan('ops:backup-verify', ['path' => $path])->assertSuccessful();
        unlink($path);
    }

    public function test_restore_drill_does_not_change_workflow_actions(): void
    {
        $path = $this->sqliteBackupWithRequiredTables();
        $before = WorkflowAction::query()->count();

        $this->artisan('ops:restore-drill', ['path' => $path])->assertSuccessful();

        $this->assertSame($before, WorkflowAction::query()->count());
        unlink($path);
    }

    public function test_administrator_can_run_backup_check_from_operations(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::admin.operations')
            ->call('verifyBackup')
            ->assertHasNoErrors()
            ->assertSee('This copy checks out.');
    }

    public function test_restore_drill_service_records_a_verification(): void
    {
        $path = $this->sqliteBackupWithRequiredTables();
        $admin = User::factory()->create()->assignRole('admin');

        $result = app(RestoreDrill::class)->run($admin, $path);

        $this->assertTrue($result->ok);
        $this->assertTrue(app(BackupVerifier::class)->sqliteHeaderValid($path));
        $this->assertDatabaseHas('ops_backup_verifications', [
            'ok' => true,
            'verified_by' => $admin->id,
        ]);
        unlink($path);
    }

    private function sqliteBackupWithRequiredTables(): string
    {
        $path = sys_get_temp_dir().'/ad-backup-'.uniqid('', true).'.sqlite';
        $pdo = new PDO('sqlite:'.$path);
        foreach (BackupVerifier::REQUIRED_TABLES as $table) {
            $pdo->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY)');
        }

        return $path;
    }
}
