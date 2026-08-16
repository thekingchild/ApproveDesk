<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_get_returns_default_for_missing_key(): void
    {
        $this->assertNull(Setting::get('brand.tagline'));
        $this->assertSame('fallback', Setting::get('brand.tagline', 'fallback'));
    }

    public function test_put_persists_and_invalidates_cache(): void
    {
        Setting::put('brand.tagline', 'first value');
        $this->assertSame('first value', Setting::get('brand.tagline'));

        Setting::put('brand.tagline', 'second value');
        $this->assertSame('second value', Setting::get('brand.tagline'));
    }

    public function test_get_result_is_cached_after_first_read(): void
    {
        Setting::query()->create(['key' => 'brand.accent_color', 'value' => '#000000']);

        $first = Setting::get('brand.accent_color');
        Setting::query()->where('key', 'brand.accent_color')->update(['value' => '#ffffff']);

        $this->assertSame('#000000', $first);
        $this->assertSame('#000000', Setting::get('brand.accent_color'), 'value should still come from cache');
    }
}
