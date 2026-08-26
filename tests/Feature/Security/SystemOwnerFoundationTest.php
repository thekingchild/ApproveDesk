<?php

namespace Tests\Feature\Security;

use App\Models\SiteLimit;
use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Users\SystemOwnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SystemOwnerFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_site_limit_singletons_are_available_after_migration(): void
    {
        $owner = User::factory()->create();

        SystemOwner::query()->create([
            'id' => SystemOwner::SINGLETON_ID,
            'user_id' => $owner->id,
        ]);

        $this->assertSame($owner->id, app(SystemOwnerService::class)->ownerId());
        $this->assertTrue($owner->isSystemOwner());
        $this->assertNull(SiteLimit::query()->findOrFail(SiteLimit::SINGLETON_ID)->max_active_users);
    }

    public function test_site_limit_rejects_non_singleton_ids(): void
    {
        $this->expectException(LogicException::class);

        SiteLimit::query()->create(['id' => 2, 'max_active_users' => null]);
    }

    public function test_system_owner_rejects_non_singleton_ids(): void
    {
        $owner = User::factory()->create();

        $this->expectException(LogicException::class);

        SystemOwner::query()->create(['id' => 2, 'user_id' => $owner->id]);
    }
}
