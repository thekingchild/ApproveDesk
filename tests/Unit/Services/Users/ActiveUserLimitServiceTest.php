<?php

namespace Tests\Unit\Services\Users;

use App\Models\SiteLimit;
use App\Models\User;
use App\Services\Users\ActiveUserLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ActiveUserLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_is_null_when_unlimited(): void
    {
        $service = app(ActiveUserLimitService::class);

        $this->assertNull($service->maximum());
        $this->assertNull($service->remaining());
        $this->assertSame(0, $service->activeCount());
    }

    public function test_run_activation_rejects_when_full(): void
    {
        User::factory()->create();
        SiteLimit::query()->whereKey(SiteLimit::SINGLETON_ID)->update(['max_active_users' => 1]);

        $this->expectException(ValidationException::class);

        app(ActiveUserLimitService::class)->runActivation(1, fn () => true);
    }

    public function test_zero_slot_updates_do_not_consume_capacity(): void
    {
        User::factory()->create();
        SiteLimit::query()->whereKey(SiteLimit::SINGLETON_ID)->update(['max_active_users' => 1]);

        $this->assertTrue(app(ActiveUserLimitService::class)->runActivation(0, fn (): bool => true));
    }
}
