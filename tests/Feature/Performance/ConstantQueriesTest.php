<?php

namespace Tests\Feature\Performance;

use App\Models\Setting;
use App\Models\User;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** RV-PERF-19: constant per-page queries. */
class ConstantQueriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_settings_come_from_the_cached_settings_array(): void
    {
        Setting::put('system.mail.from_name', 'Approvals');
        Setting::cached();
        SystemSettings::flush();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $value = SystemSettings::value('mail.from_name');
        DB::disableQueryLog();

        $this->assertSame('Approvals', $value);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_both_notification_indicators_share_one_unread_count_query(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test('notification-indicator');
        Livewire::test('notification-indicator', ['mobile' => true]);
        DB::disableQueryLog();

        $counts = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "notifications"'));
        $this->assertCount(1, $counts);
    }
}
