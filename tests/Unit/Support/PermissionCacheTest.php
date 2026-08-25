<?php

namespace Tests\Unit\Support;

use App\Support\PermissionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
}
