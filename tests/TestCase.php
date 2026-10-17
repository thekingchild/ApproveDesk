<?php

namespace Tests;

use App\Contracts\DnsResolver;
use App\Support\OutboundDestination;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;
use Tests\Support\ArrayDnsResolver;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run when a cached config/route file would override phpunit.xml,
     * so RefreshDatabase can never migrate:fresh a developer's real database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached() || $app->routesAreCached()) {
            throw new RuntimeException('Cached configuration or routes detected. Run `php artisan optimize:clear` before running tests.');
        }

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        $isolated = $connection === 'sqlite' ? $database === ':memory:' : str_contains($database, 'test');

        if (! $app->environment('testing') || ! $isolated) {
            throw new RuntimeException("Tests must run with APP_ENV=testing on in-memory SQLite or a *test* database; got [{$connection}:{$database}].");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $resolver = new ArrayDnsResolver;
        $this->app->instance(DnsResolver::class, $resolver);
        $this->app->instance(OutboundDestination::class, new OutboundDestination($resolver));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
