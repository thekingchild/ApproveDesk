<?php

namespace Tests\Unit\Support;

use App\Providers\AppServiceProvider;
use App\Support\PermissionCache;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_forget_if_ready_does_not_throw_when_cache_table_is_missing(): void
    {
        Schema::dropIfExists('cache');

        PermissionCache::forgetIfReady();

        $this->assertFalse(Schema::hasTable('cache'));
    }

    public function test_requests_do_not_clear_the_permission_cache_but_migrations_do(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->getPermissions();
        $store = $registrar->getCacheRepository();
        $this->assertTrue($store->has($registrar->cacheKey), 'permissions are cached after first use');

        // Every request boots the providers again.
        (new AppServiceProvider($this->app))->boot();
        $this->assertTrue($store->has($registrar->cacheKey), 'booting a request must not clear the cache (RV-PERF-13)');

        event(new MigrationsEnded('up'));
        $this->assertFalse($store->has($registrar->cacheKey), 'migrations clear it');
    }
}
