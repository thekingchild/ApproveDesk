<?php

namespace App\Services\Install;

use App\Enums\SystemRole;
use App\Exceptions\InstallerException;
use App\Models\SiteLimit;
use App\Models\SystemOwner;
use App\Models\User;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Parser\Parser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PDO;
use RuntimeException;

/**
 * First-run web installer: requirements check, environment writing,
 * migrations, first admin creation, and the completion lock.
 *
 * Every path the installer touches is overridable via config/installer.php
 * so tests can run the whole flow without touching the real .env or lock.
 */
class Installer
{
    /** Extensions ApproveDesk cannot run without. */
    private const REQUIRED_EXTENSIONS = [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'mbstring',
        'openssl', 'pdo', 'tokenizer', 'xml', 'zip',
    ];

    /** Nice-to-have extensions surfaced as warnings, not blockers. gmp signs browser push messages. */
    private const RECOMMENDED_EXTENSIONS = ['gd', 'gmp', 'pdo_mysql', 'pdo_sqlite'];

    public function isInstalled(): bool
    {
        if (config('installer.installed')) {
            return true;
        }

        return is_file($this->lockPath());
    }

    /**
     * Is the installer flow currently active? Null config means auto:
     * active everywhere except local/testing (so dev and tests are never
     * hijacked by the redirect-to-installer middleware).
     */
    public function isActive(): bool
    {
        $forced = config('installer.enabled');

        if (is_bool($forced)) {
            return $forced;
        }

        return ! app()->environment('local', 'testing');
    }

    public function lockPath(): string
    {
        return (string) config('installer.lock_path');
    }

    public function envPath(): string
    {
        return config('installer.env_path') ?: base_path('.env');
    }

    /**
     * @return array<int, array{label: string, ok: bool, note: string, required: bool}>
     */
    public function requirements(): array
    {
        $requirements = [
            ['label' => 'PHP >= 8.4.1', 'ok' => PHP_VERSION_ID >= 80401, 'note' => PHP_VERSION, 'required' => true], // @phpstan-ignore greaterOrEqual.alwaysTrue (defensive: hosts without composer-verified PHP)
        ];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $requirements[] = [
                'label' => "ext/{$extension}",
                'ok' => extension_loaded($extension),
                'note' => extension_loaded($extension) ? 'loaded' : 'missing',
                'required' => true,
            ];
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $extension) {
            $requirements[] = [
                'label' => "ext/{$extension}",
                'ok' => extension_loaded($extension),
                'note' => extension_loaded($extension) ? 'loaded' : 'recommended, not loaded',
                'required' => false,
            ];
        }

        foreach ($this->writablePaths() as $path => $note) {
            $requirements[] = [
                'label' => "writable: {$path}",
                'ok' => is_writable($path) || $this->canCreate($path),
                'note' => $note,
                'required' => true,
            ];
        }

        return $requirements;
    }

    /**
     * @return array<string, string>
     */
    private function writablePaths(): array
    {
        return [
            dirname($this->envPath()) => 'application directory (.env creation)',
            storage_path('framework') => 'framework cache & sessions',
            storage_path('logs') => 'log files',
            base_path('bootstrap/cache') => 'package manifests',
        ];
    }

    private function canCreate(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        return is_writable(dirname($path)) && @mkdir($path, 0755, true);
    }

    public function meetsRequirements(): bool
    {
        foreach ($this->requirements() as $requirement) {
            if ($requirement['required'] && ! $requirement['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Minimal boot environment so the installer pages themselves render
     * (a fresh deploy may have no .env at all): copy the example file and
     * make sure an APP_KEY exists for cookie encryption.
     *
     * Also pin file session/cache for this request. The example file uses
     * database stores, which 500 before migrations exist on cPanel/Plesk.
     */
    public function ensureBootstrapEnvironment(): void
    {
        $envPath = $this->envPath();
        $createdEnv = ! is_file($envPath);

        if ($createdEnv) {
            $example = base_path('.env.example');
            $contents = is_file($example) ? file_get_contents($example) : "APP_NAME=ApproveDesk\n";
            file_put_contents($envPath, (string) $contents);
            $this->writeEnvValue('APP_ENV', 'production');
            $this->writeEnvValue('APP_DEBUG', 'false');
            $this->writeEnvValue('SESSION_DRIVER', 'file');
            $this->writeEnvValue('CACHE_STORE', 'file');
        }

        $key = trim((string) $this->readEnvValue('APP_KEY'));
        $keyWasEmpty = $key === '';
        if ($keyWasEmpty) {
            $key = $this->generateKey();
            $this->writeEnvValue('APP_KEY', $key);
            if (! $createdEnv) {
                if ($this->readEnvValue('SESSION_DRIVER') === 'database') {
                    $this->writeEnvValue('SESSION_DRIVER', 'file');
                }
                if ($this->readEnvValue('CACHE_STORE') === 'database') {
                    $this->writeEnvValue('CACHE_STORE', 'file');
                }
            }
        }

        $needsFileStores = $createdEnv || $keyWasEmpty || trim((string) config('app.key')) === '';

        if (trim((string) config('app.key')) === '') {
            config()->set('app.key', $key);
        }

        if ($needsFileStores) {
            if (config('session.driver') === 'database') {
                config()->set('session.driver', 'file');
            }
            if (config('cache.default') === 'database') {
                config()->set('cache.default', 'file');
            }
        }
    }

    /**
     * Verify database credentials before anything is persisted.
     *
     * @param  array{connection: string, host: string, port: string, database: string, username: string, password: ?string, path: ?string}  $credentials
     *
     * @throws InstallerException when the connection fails
     */
    public function testDatabaseConnection(array $credentials): void
    {
        try {
            if ($credentials['connection'] === 'sqlite') {
                $path = database_path((string) $credentials['path']);
                $directory = dirname($path);
                if (! is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }
                if (! is_file($path)) {
                    touch($path);
                }

                new PDO('sqlite:'.$path);

                return;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                $credentials['host'],
                $credentials['port'] ?: '3306',
                $credentials['database'],
            );

            new PDO($dsn, $credentials['username'], (string) $credentials['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $exception) {
            throw new InstallerException('Database connection failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Persist the chosen database configuration (and app basics) to .env.
     *
     * @param  array{connection: string, host: string, port: string, database: string, username: string, password: ?string, path: ?string, url: ?string}  $input
     */
    public function writeEnvironment(array $input): void
    {
        $values = [
            'APP_NAME' => 'ApproveDesk',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $input['url'] ?: request()->getSchemeAndHttpHost(),
            'APP_KEY' => $this->readEnvValue('APP_KEY') ?: $this->generateKey(),
            'DB_CONNECTION' => $input['connection'],
        ];

        $timezone = trim((string) $this->readEnvValue('APP_TIMEZONE'));
        if ($timezone === '') {
            $values['APP_TIMEZONE'] = 'UTC';
        }

        if ($input['connection'] === 'sqlite') {
            $values['DB_DATABASE'] = database_path((string) $input['path']);
        } else {
            $values += [
                'DB_HOST' => $input['host'],
                'DB_PORT' => $input['port'] ?: '3306',
                'DB_DATABASE' => $input['database'],
                'DB_USERNAME' => $input['username'],
                'DB_PASSWORD' => (string) $input['password'],
            ];
        }

        foreach ($values as $key => $value) {
            $this->writeEnvValue($key, $value);
        }

        config()->set('app.key', $values['APP_KEY']);
        config()->set('app.url', $values['APP_URL']);
    }

    /**
     * Apply the database saved in .env before migrate. Skips PHPUnit's
     * in-memory SQLite so installer tests keep using RefreshDatabase.
     */
    public function applySavedDatabaseConfiguration(): void
    {
        $connection = $this->plainEnvValue('DB_CONNECTION');
        if ($connection === '') {
            return;
        }

        if ($connection === 'sqlite' && config('database.connections.sqlite.database') === ':memory:') {
            return;
        }

        $this->syncRuntimeFromEnvFile();
    }

    /**
     * Point Laravel at the database written to .env. Needed because php-fpm
     * may still have sqlite from the copied example file after MySQL is saved.
     */
    public function syncRuntimeFromEnvFile(): void
    {
        $connection = $this->plainEnvValue('DB_CONNECTION');
        if ($connection === '') {
            return;
        }

        config()->set('database.default', $connection);

        if ($connection === 'mysql') {
            config()->set('database.connections.mysql.host', $this->plainEnvValue('DB_HOST') ?: '127.0.0.1');
            config()->set('database.connections.mysql.port', $this->plainEnvValue('DB_PORT') ?: '3306');
            config()->set('database.connections.mysql.database', $this->plainEnvValue('DB_DATABASE'));
            config()->set('database.connections.mysql.username', $this->plainEnvValue('DB_USERNAME'));
            config()->set('database.connections.mysql.password', $this->plainEnvValue('DB_PASSWORD'));
            DB::purge('mysql');
        } else {
            $database = $this->plainEnvValue('DB_DATABASE');
            if ($database !== '') {
                config()->set('database.connections.sqlite.database', $database);
            }
            if (! app()->environment('testing')) {
                DB::purge('sqlite');
            }
        }

        $url = $this->plainEnvValue('APP_URL');
        if ($url !== '') {
            config()->set('app.url', $url);
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if ($scheme === 'https') {
                URL::forceScheme('https');
            }
        }
    }

    /**
     * @return array{migration_output: string, seeded: bool}
     */
    public function runMigrations(): array
    {
        $this->applySavedDatabaseConfiguration();

        Artisan::call('migrate', ['--force' => true]);
        $migrationOutput = Artisan::output();

        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\RolesAndPermissionsSeeder',
            '--force' => true,
        ]);
        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\SettingsSeeder',
            '--force' => true,
        ]);

        if (! app()->environment('testing')) {
            try {
                Artisan::call('storage:link');
            } catch (\Throwable) {
                // Symlinks fail on some shared hosts; branding assets use a route fallback.
            }
        }

        return ['migration_output' => $migrationOutput, 'seeded' => true];
    }

    /**
     * @param  array{name: string, email: string, password: string}  $input
     */
    public function createAdministrator(array $input): User
    {
        return DB::transaction(function () use ($input): User {
            $owner = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            // email_verified_at is intentionally not mass-assignable.
            $owner->forceFill(['email_verified_at' => now()])->save();
            $owner->assignRole(SystemRole::SuperAdmin->value);

            SystemOwner::query()->create([
                'id' => SystemOwner::SINGLETON_ID,
                'user_id' => $owner->id,
            ]);
            SiteLimit::query()->firstOrCreate(['id' => SiteLimit::SINGLETON_ID], ['max_active_users' => null]);

            return $owner;
        });
    }

    /**
     * Final step: keep debug off, mark installed, write the lock file.
     */
    public function finalize(): void
    {
        $this->writeEnvValue('APP_DEBUG', 'false');
        $this->writeEnvValue('APP_INSTALLED', 'true');

        $lockPath = $this->lockPath();
        $directory = dirname($lockPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (! file_put_contents($lockPath, now()->toIso8601String()) && ! is_file($lockPath)) {
            throw new RuntimeException('Could not write the installer lock file.');
        }
    }

    public function generateKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    private function readEnvValue(string $key): ?string
    {
        if (! is_file($this->envPath())) {
            return null;
        }

        $contents = (string) file_get_contents($this->envPath());

        try {
            foreach ((new Parser)->parse($contents) as $entry) {
                if ($entry->getName() !== $key) {
                    continue;
                }

                return $entry->getValue()->map(fn ($value) => $value->getChars())->getOrElse('');
            }
        } catch (InvalidFileException) {
            return $this->readEnvValueFallback($key, $contents);
        }

        return null;
    }

    private function readEnvValueFallback(string $key, string $contents): ?string
    {
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];

        foreach ($lines as $line) {
            if (str_starts_with($line, "{$key}=")) {
                return $this->decodeEnvValue(substr($line, strlen($key) + 1));
            }
        }

        return null;
    }

    private function plainEnvValue(string $key): string
    {
        return (string) $this->readEnvValue($key);
    }

    private function writeEnvValue(string $key, string $value): void
    {
        $envPath = $this->envPath();
        $contents = is_file($envPath) ? (string) file_get_contents($envPath) : '';
        $encoded = $this->encodeEnvValue($value);

        $pattern = is_file($envPath) ? '/^'.preg_quote($key, '/').'.*/m' : '';
        $line = "{$key}={$encoded}";

        if ($pattern !== '' && preg_match($pattern, $contents)) {
            $contents = (string) preg_replace($pattern, $line, $contents);
        } else {
            $contents = trim($contents)."\n{$line}\n";
        }

        if (file_put_contents($envPath, $contents) === false) {
            throw new RuntimeException("Could not write to [{$envPath}]. Check file permissions.");
        }
    }

    private function encodeEnvValue(string $value): string
    {
        if ($value === '' || str_starts_with($value, 'base64:')) {
            return $value;
        }

        if (! preg_match('/[\s"\'#\\\\$]/', $value)) {
            return $value;
        }

        return '"'.str_replace(
            ['\\', '"', '$'],
            ['\\\\', '\\"', '\\$'],
            $value,
        ).'"';
    }

    private function decodeEnvValue(string $raw): string
    {
        $raw = trim($raw);

        if (str_starts_with($raw, '"') && str_ends_with($raw, '"') && strlen($raw) >= 2) {
            $inner = substr($raw, 1, -1);

            return str_replace(['\\$', '\\"', '\\\\'], ['$', '"', '\\'], $inner);
        }

        if (str_starts_with($raw, "'") && str_ends_with($raw, "'") && strlen($raw) >= 2) {
            return substr($raw, 1, -1);
        }

        return $raw;
    }
}
