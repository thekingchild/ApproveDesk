<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Install\Installer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class InstallerFreshDeployTest extends TestCase
{
    private string $envFile;

    private string $lockFile;

    private string $missingDatabaseFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFile = storage_path('framework/testing/fresh-install.env');
        $this->lockFile = storage_path('framework/testing/fresh-install.lock');
        $this->missingDatabaseFile = storage_path('framework/testing/missing-installed.sqlite');

        @unlink($this->envFile);
        @unlink($this->lockFile);
        @unlink($this->missingDatabaseFile);

        config([
            'app.key' => '',
            'session.driver' => 'database',
            'cache.default' => 'database',
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
        @unlink($this->missingDatabaseFile);

        parent::tearDown();
    }

    #[TestDox('a fresh cPanel zip can open /install with no .env, no APP_KEY, and no database')]
    public function test_install_welcome_renders_without_env_key_or_database(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('PHP >= 8.4.1');
    }

    #[TestDox('settings reads return the default when the cache or settings table is missing')]
    public function test_setting_get_returns_default_when_database_is_unavailable(): void
    {
        $this->assertSame('0', Setting::get('turnstile.enabled', '0'));
    }

    #[TestDox('installed apps fail closed when a settings read is unavailable')]
    public function test_setting_get_rethrows_failures_after_installation(): void
    {
        config([
            'installer.installed' => true,
            'cache.default' => 'database',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->missingDatabaseFile,
        ]);
        DB::purge('sqlite');
        Cache::forgetDriver('database');

        $this->expectException(QueryException::class);

        Setting::get('auth.enforce_2fa', '0');
    }

    #[TestDox('the public installer keeps debug disabled throughout first run')]
    public function test_public_installer_never_enables_debug(): void
    {
        $installer = app(Installer::class);
        $installer->ensureBootstrapEnvironment();

        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', (string) file_get_contents($this->envFile));

        $installer->writeEnvironment([
            'connection' => 'sqlite',
            'host' => '',
            'port' => '',
            'database' => '',
            'username' => '',
            'password' => null,
            'path' => 'install-test.sqlite',
            'url' => 'https://approvedesk.example.test',
        ]);

        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', (string) file_get_contents($this->envFile));
    }

    #[TestDox('first-run bootstrap writes APP_KEY and file session/cache so the wizard can boot')]
    public function test_ensure_bootstrap_environment_prepares_first_run_stores(): void
    {
        app(Installer::class)->ensureBootstrapEnvironment();

        $env = (string) file_get_contents($this->envFile);

        $this->assertStringContainsString('APP_KEY=base64:', $env);
        $this->assertMatchesRegularExpression('/^SESSION_DRIVER=file$/m', $env);
        $this->assertMatchesRegularExpression('/^CACHE_STORE=file$/m', $env);
        $this->assertNotSame('', (string) config('app.key'));
        $this->assertSame('file', config('session.driver'));
        $this->assertSame('file', config('cache.default'));
    }
}
