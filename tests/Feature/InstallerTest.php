<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SiteLimit;
use App\Models\SystemOwner;
use App\Models\User;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private string $envFile;

    private string $lockFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFile = storage_path('framework/testing/installer.env');
        $this->lockFile = storage_path('framework/testing/installed.lock');

        @unlink($this->envFile);
        @unlink($this->lockFile);
        @unlink(database_path('install-test.sqlite'));

        config([
            'installer.enabled' => true,
            'installer.installed' => false,
            'installer.env_path' => $this->envFile,
            'installer.lock_path' => $this->lockFile,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
        @unlink($this->lockFile);
        @unlink(database_path('install-test.sqlite'));

        parent::tearDown();
    }

    #[TestDox('guests are redirected to the installer while the app is not installed')]
    public function test_guests_are_redirected_to_the_installer(): void
    {
        $this->get('/login')->assertRedirect(route('install.welcome'));
        $this->get('/')->assertRedirect(route('install.welcome'));
    }

    #[TestDox('the welcome screen lists server requirements')]
    public function test_welcome_screen_lists_requirements(): void
    {
        $this->get(route('install.welcome'))
            ->assertOk()
            ->assertSee('PHP >= 8.3')
            ->assertSee('ext/mbstring');
    }

    #[TestDox('steps cannot be skipped ahead')]
    public function test_steps_cannot_be_skipped(): void
    {
        $this->get(route('install.admin'))->assertRedirect(route('install.migrate'));
        $this->get(route('install.migrate'))->assertRedirect(route('install.database'));
    }

    #[TestDox('invalid database credentials are rejected with a readable error')]
    public function test_bad_database_credentials_are_rejected(): void
    {
        $this->post(route('install.database.store'), [
            'connection' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '59999',
            'database' => 'approval_workflow_db',
            'username' => 'db_user',
            'password' => 'secret',
        ])->assertSessionHas('error');

        $this->assertStringContainsString(
            'Database connection failed',
            (string) session('error'),
        );

        // The flow must not advance past the database step on failure.
        $this->get(route('install.migrate'))->assertRedirect(route('install.database'));
    }

    #[TestDox('the full sqlite install flow locks the installer and creates the admin')]
    public function test_full_install_flow_with_sqlite(): void
    {
        // Step 1-2: requirements pass, database form submitted with sqlite.
        $this->post(route('install.database.store'), [
            'connection' => 'sqlite',
            'path' => 'install-test.sqlite',
        ])->assertRedirect(route('install.migrate'));

        $this->assertFileExists($this->envFile);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', (string) file_get_contents($this->envFile));
        $this->assertStringContainsString('APP_KEY=base64:', (string) file_get_contents($this->envFile));

        // Step 3: migrations + base seeders.
        $this->post(route('install.migrate.run'))
            ->assertRedirect(route('install.admin'));

        $this->get(route('install.admin'))->assertOk()->assertSee('System owner');

        $this->assertDatabaseHas('roles', ['name' => 'super-admin']);
        $this->assertSame('#4f46e5', Setting::get('brand.accent_color'));

        // Step 4: first administrator.
        $this->post(route('install.admin.store'), [
            'name' => 'First Admin',
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertRedirect(route('login'));

        $admin = User::query()->where('email', 'admin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('super-admin'));
        $this->assertTrue($admin->isSystemOwner());
        $this->assertDatabaseHas('system_owners', ['id' => SystemOwner::SINGLETON_ID, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('site_limits', ['id' => SiteLimit::SINGLETON_ID, 'max_active_users' => null]);
        $this->assertNotNull($admin->email_verified_at);

        $this->assertFileExists($this->lockFile);
        $env = (string) file_get_contents($this->envFile);
        $this->assertStringContainsString('APP_INSTALLED=true', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('ApproveDesk is ready')
            ->assertSee('data-test="install-complete"', false);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('ApproveDesk is ready');

        foreach ([
            'install.welcome',
            'install.database',
            'install.migrate',
            'install.admin',
            'install.done',
        ] as $route) {
            $this->get(route($route))->assertNotFound();
        }

        $this->get('/install')->assertNotFound();
        $this->actingAs($admin)->get(route('install.welcome'))->assertNotFound();
        $this->actingAs($admin)->get(route('install.done'))->assertNotFound();
    }

    #[TestDox('the APP_INSTALLED env flag alone also counts as installed')]
    public function test_env_flag_counts_as_installed(): void
    {
        config(['installer.installed' => true]);

        $this->assertTrue(app(Installer::class)->isInstalled());
        $this->get(route('install.welcome'))->assertNotFound();
    }

    #[TestDox('an installed app hides the installer even when the wizard is inactive')]
    public function test_installed_app_hides_installer_when_wizard_is_inactive(): void
    {
        config([
            'installer.enabled' => false,
            'installer.installed' => true,
        ]);

        $this->get(route('install.welcome'))->assertNotFound();
        $this->get(route('install.done'))->assertNotFound();
        $this->get('/install')->assertNotFound();
        $this->get('/login')->assertOk();
    }
}
