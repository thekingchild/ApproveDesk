<?php

namespace App\Http\Controllers;

use App\Exceptions\InstallerException;
use App\Support\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Classic form-driven wizard (no Livewire): each step boots the application
 * fresh, so rewriting .env mid-install is safe — the next request picks up
 * the new configuration.
 */
class InstallController extends Controller
{
    public function __construct(private readonly Installer $installer) {}

    public function welcome(): View
    {
        return view('install.welcome', [
            'requirements' => $this->installer->requirements(),
            'meets' => $this->installer->meetsRequirements(),
        ]);
    }

    public function showDatabase(): RedirectResponse|View
    {
        if (! $this->installer->meetsRequirements()) {
            return redirect()->route('install.welcome')
                ->with('error', 'Server requirements are not met yet.');
        }

        return view('install.database');
    }

    public function storeDatabase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'connection' => ['required', 'in:mysql,sqlite'],
            'host' => ['required_if:connection,mysql', 'nullable', 'string', 'max:255'],
            'port' => ['nullable', 'string', 'max:5'],
            'database' => ['required_if:connection,mysql', 'nullable', 'string', 'max:255'],
            'username' => ['required_if:connection,mysql', 'nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'path' => ['required_if:connection,sqlite', 'nullable', 'string', 'max:100', 'regex:/^[\w.-]+\.sqlite$/'],
            'url' => ['nullable', 'url', 'max:255'],
        ]);

        /** @var array{connection: string, host: string, port: string, database: string, username: string, password: string|null, path: string|null, url: string|null} $input */
        $input = [
            'connection' => (string) $validated['connection'],
            'host' => (string) ($validated['host'] ?? ''),
            'port' => (string) ($validated['port'] ?? ''),
            'database' => (string) ($validated['database'] ?? ''),
            'username' => (string) ($validated['username'] ?? ''),
            'password' => isset($validated['password']) ? (string) $validated['password'] : null,
            'path' => isset($validated['path']) ? (string) $validated['path'] : null,
            'url' => isset($validated['url']) ? (string) $validated['url'] : null,
        ];

        try {
            $this->installer->testDatabaseConnection($input);
        } catch (InstallerException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $this->installer->writeEnvironment($input);

        $request->session()->put('install.database_configured', true);
        $request->session()->forget('install.migrated');

        return redirect()->route('install.migrate');
    }

    public function showMigrate(Request $request): RedirectResponse|View
    {
        if (! $request->session()->get('install.database_configured')) {
            return redirect()->route('install.database');
        }

        return view('install.migrate');
    }

    public function runMigrate(Request $request): RedirectResponse
    {
        if (! $request->session()->get('install.database_configured')) {
            return redirect()->route('install.database');
        }

        $result = $this->installer->runMigrations();

        $request->session()->put('install.migrated', true);

        return redirect()->route('install.admin')
            ->with('status', 'Migrations complete. '.$result['migration_output']);
    }

    public function showAdmin(Request $request): RedirectResponse|View
    {
        if (! $request->session()->get('install.migrated')) {
            return redirect()->route('install.migrate');
        }

        return view('install.admin');
    }

    public function storeAdmin(Request $request): RedirectResponse
    {
        if (! $request->session()->get('install.migrated')) {
            return redirect()->route('install.migrate');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $this->installer->createAdministrator($validated);
        $this->installer->finalize();

        $request->session()->forget(['install.database_configured', 'install.migrated']);
        $request->session()->put('install.completed', true);

        return redirect()->route('login');
    }

    public function done(Request $request): RedirectResponse|View
    {
        if ($this->installer->isInstalled()) {
            abort(404);
        }

        return redirect()->route('install.welcome');
    }
}
