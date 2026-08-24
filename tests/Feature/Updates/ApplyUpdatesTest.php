<?php

namespace Tests\Feature\Updates;

use App\Actions\Updates\ApplyDatabaseUpdatesAction;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Services\Operations\MigrationPreflight;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ApplyUpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_submitter_cannot_open_updates(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)
            ->get(route('admin.updates.index'))
            ->assertForbidden();
    }

    public function test_administrator_sees_version_and_empty_pending_list(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.updates.index'))
            ->assertOk()
            ->assertSee('Updates')
            ->assertSee('data-test="updates-version"', false)
            ->assertSee('None. This copy is up to date.')
            ->assertSee('Take a host backup first')
            ->assertSee('data-test="contextual-help"', false)
            ->assertSee(route('help.show', 'updates'), false);
    }

    public function test_check_updates_reports_when_nothing_is_pending(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::admin.updates')
            ->call('checkUpdates')
            ->assertHasNoErrors()
            ->assertSee('No pending database updates.');
    }

    public function test_apply_with_clean_preflight_does_not_change_workflow_actions(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $before = WorkflowAction::query()->count();

        Livewire::actingAs($admin)
            ->test('pages::admin.updates')
            ->set('confirmation', ApplyDatabaseUpdatesAction::CONFIRMATION)
            ->call('apply')
            ->assertHasNoErrors()
            ->assertSee('Nothing to apply.');

        $this->assertSame($before, WorkflowAction::query()->count());
        $this->assertNotNull(Setting::get('updates.last_applied_at'));
    }

    public function test_apply_without_confirmation_is_refused(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::admin.updates')
            ->set('confirmation', 'please')
            ->call('apply')
            ->assertHasErrors('confirmation');
    }

    public function test_apply_refuses_a_destructive_pending_migration(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $path = database_path('migrations/2099_01_01_000000_drop_demo_column.php');
        file_put_contents($path, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
PHP);

        try {
            $pending = app(MigrationPreflight::class)->pendingNames();
            $this->assertContains('2099_01_01_000000_drop_demo_column', $pending, implode(',', $pending));
            $this->assertNotSame([], app(MigrationPreflight::class)->inspectPending());

            Livewire::actingAs($admin)
                ->test('pages::admin.updates')
                ->set('confirmation', ApplyDatabaseUpdatesAction::CONFIRMATION)
                ->call('apply')
                ->assertHasErrors('confirmation');

            $this->assertTrue(Schema::hasColumn('users', 'name'));
        } finally {
            @unlink($path);
        }
    }
}
