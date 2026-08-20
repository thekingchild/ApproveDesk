<?php

namespace Tests\Unit\Collaboration;

use App\Models\Setting;
use App\Services\Collaboration\QuietHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuietHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_settings_are_never_quiet(): void
    {
        $this->assertFalse(app(QuietHours::class)->isQuiet());
        $open = app(QuietHours::class)->nextOpenAt();
        $this->assertTrue($open->gte(now()->subSecond()) && $open->lte(now()->addSecond()));
    }

    public function test_same_day_window_and_overnight_window(): void
    {
        Setting::put('nudge.quiet_hours', json_encode(['start' => '12:00', 'end' => '13:00']));
        Carbon::setTestNow('2026-08-20 12:30:00');
        $hours = app(QuietHours::class);
        $this->assertTrue($hours->isQuiet());
        $this->assertSame('13:00:00', $hours->nextOpenAt()->format('H:i:s'));

        Carbon::setTestNow('2026-08-20 11:00:00');
        $this->assertFalse(app(QuietHours::class)->isQuiet());

        Setting::put('nudge.quiet_hours', json_encode(['start' => '22:00', 'end' => '07:00']));
        Carbon::setTestNow('2026-08-20 23:00:00');
        $this->assertTrue(app(QuietHours::class)->isQuiet());
        $this->assertSame('2026-08-21 07:00:00', app(QuietHours::class)->nextOpenAt()->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-08-21 02:00:00');
        $this->assertTrue(app(QuietHours::class)->isQuiet());
        $this->assertSame('2026-08-21 07:00:00', app(QuietHours::class)->nextOpenAt()->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-08-21 08:00:00');
        $this->assertFalse(app(QuietHours::class)->isQuiet());
    }

    public function test_encode_rejects_identical_times_and_clears_empty(): void
    {
        $this->assertNull(QuietHours::encode('', ''));
        $this->expectException(\InvalidArgumentException::class);
        QuietHours::encode('22:00', '22:00');
    }
}
