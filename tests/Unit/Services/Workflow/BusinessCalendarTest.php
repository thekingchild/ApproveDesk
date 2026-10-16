<?php

namespace Tests\Unit\Services\Workflow;

use App\Models\Setting;
use App\Services\Workflow\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BusinessCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_unset_hours_keep_hour_tick_weekend_skip(): void
    {
        $calendar = $this->calendar(hours: [], holidays: ['2026-12-25']);
        $deadline = $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-24 09:00:00'), 24);

        $this->assertSame('2026-12-28 09:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    /** RV-BUG-34: Sunday 23:00-24:00 is not working time, so 8 hours end at 08:00, not 07:00. */
    public function test_weekend_start_counts_from_monday_midnight(): void
    {
        $calendar = $this->calendar(hours: []);
        $deadline = $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-26 09:00:00'), 8);

        $this->assertSame('2026-12-28 08:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    /** RV-BUG-34: 15 hours from Thursday 09:00 are used up at the end of Thursday. */
    public function test_hours_used_up_at_the_end_of_a_working_day_land_on_its_midnight(): void
    {
        $calendar = $this->calendar(hours: [], holidays: ['2026-12-25']);
        $deadline = $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-24 09:00:00'), 15);

        $this->assertSame('2026-12-25 00:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    /** RV-BUG-34: partial hours on either side of a non-working day are counted exactly. */
    public function test_24_hour_mode_counts_partial_hours_at_day_boundaries(): void
    {
        $calendar = $this->calendar(hours: []);

        // Sunday 23:30 + 1h: the Sunday half hour does not count.
        $this->assertSame('2026-12-28 01:00:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-27 23:30:00'), 1)->format('Y-m-d H:i:s'));
        // Friday 23:30 + 1h: half an hour on Friday, the rest on Monday.
        $this->assertSame('2026-12-28 00:30:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-25 23:30:00'), 1)->format('Y-m-d H:i:s'));
        // Within one working day nothing changes.
        $this->assertSame('2026-12-22 13:00:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-22 09:00:00'), 4)->format('Y-m-d H:i:s'));
    }

    public function test_24_hour_mode_without_any_working_day_fails_instead_of_looping(): void
    {
        $calendar = new BusinessCalendar([], [], []);

        $this->expectException(RuntimeException::class);
        $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-22 09:00:00'), 1);
    }

    public function test_zero_hours_returns_start(): void
    {
        $start = CarbonImmutable::parse('2026-12-21 09:00:30');
        $calendar = $this->calendar(['start' => '09:00', 'end' => '17:00']);

        $this->assertTrue($start->equalTo($calendar->addBusinessHours($start, 0)));
        $this->assertTrue($start->equalTo($this->calendar(hours: [])->addBusinessHours($start, 0)));
    }

    public function test_windowed_oracles(): void
    {
        $hours = ['start' => '09:00', 'end' => '17:00'];
        $calendar = $this->calendar($hours);

        $this->assertSame('2026-12-21 17:00:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-21 09:00:00'), 8)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-22 16:00:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-21 16:00:00'), 8)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-21 17:00:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-21 08:00:00'), 8)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-22 17:00:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-21 17:00:00'), 8)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-22 09:00:30', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-21 09:00:30'), 8)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-22 09:59:30', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-21 16:59:30'), 1)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-28 16:00:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-25 16:00:00'), 8)->format('Y-m-d H:i:s'));
    }

    public function test_friday_by_day_close_and_spill(): void
    {
        $calendar = $this->calendar([
            'start' => '09:00',
            'end' => '17:00',
            'by_day' => ['5' => ['start' => '09:00', 'end' => '13:00']],
        ]);

        $this->assertSame('2026-12-25 13:00:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-25 09:00:00'), 4)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-28 12:00:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-25 12:00:00'), 4)->format('Y-m-d H:i:s'));
    }

    public function test_is_working_excludes_exclusive_end(): void
    {
        $calendar = $this->calendar(['start' => '09:00', 'end' => '17:00']);
        $inside = CarbonImmutable::parse('2026-12-21 16:59:00');
        $end = CarbonImmutable::parse('2026-12-21 17:00:00');

        $this->assertTrue($calendar->isWorking($inside));
        $this->assertTrue($calendar->isWorkingDay($end));
        $this->assertFalse($calendar->isWorking($end));
    }

    public function test_by_day_on_non_working_weekday_is_ignored(): void
    {
        $calendar = $this->calendar(
            [
                'start' => '09:00',
                'end' => '17:00',
                'by_day' => ['6' => ['start' => '09:00', 'end' => '12:00']],
            ],
            days: [1, 2, 3, 4, 5],
        );

        $saturday = CarbonImmutable::parse('2026-12-26 10:00:00');
        $this->assertFalse($calendar->isWorkingDay($saturday));
        $this->assertFalse($calendar->isWorking($saturday));
    }

    public function test_next_countable_start_from_holiday_midnight_and_window_end(): void
    {
        $calendar = $this->calendar(['start' => '09:00', 'end' => '17:00'], holidays: ['2026-12-25']);

        $this->assertSame(
            '2026-12-28 09:00:00',
            $calendar->nextCountableStart(CarbonImmutable::parse('2026-12-25 00:00:00'))->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-12-22 09:00:00',
            $calendar->nextCountableStart(CarbonImmutable::parse('2026-12-21 17:00:00'))->format('Y-m-d H:i:s'),
        );
    }

    public function test_corrupt_settings_json_falls_back_to_twenty_four_hour_mode(): void
    {
        Setting::put('calendar.working_days', '1,2,3,4,5');
        Setting::put('calendar.working_hours', '{not-json');
        $calendar = new BusinessCalendar;
        $deadline = $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-24 09:00:00'), 24);

        $this->assertSame('2026-12-25 09:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_invalid_by_day_keeps_default_window(): void
    {
        Setting::put('calendar.working_hours', json_encode([
            'start' => '09:00',
            'end' => '17:00',
            'by_day' => ['5' => 'broken'],
        ]));
        $calendar = new BusinessCalendar;

        $this->assertSame('2026-12-25 17:00:00', $calendar->addBusinessHours(CarbonImmutable::parse('2026-12-25 09:00:00'), 8)->format('Y-m-d H:i:s'));
    }

    public function test_overnight_window_is_not_stored_as_working_hours(): void
    {
        Setting::put('calendar.working_hours', json_encode(['start' => '22:00', 'end' => '06:00']));
        $calendar = new BusinessCalendar;

        $this->assertNull($calendar->workingHours());
    }

    public function test_empty_working_days_override_throws_instead_of_hanging(): void
    {
        $calendar = new BusinessCalendar(
            workingDaysOverride: [],
            holidaysOverride: [],
            workingHoursOverride: ['start' => '09:00', 'end' => '09:01'],
        );

        $this->expectException(RuntimeException::class);
        $calendar->addBusinessHours(CarbonImmutable::parse('2026-01-05 09:00:00'), 1);
    }

    public function test_max_sla_window_skip_completes_quickly(): void
    {
        $calendar = $this->calendar(['start' => '09:00', 'end' => '17:00']);
        $started = microtime(true);
        $deadline = $calendar->addBusinessHours(CarbonImmutable::parse('2026-01-05 09:00:00'), 8760);

        $this->assertLessThan(10.0, microtime(true) - $started);
        $this->assertSame('17:00:00', $deadline->format('H:i:s'));
        $this->assertTrue($deadline->greaterThan(CarbonImmutable::parse('2026-01-05 09:00:00')));
    }

    /**
     * @param  array{start?: string, end?: string, by_day?: array<string, array{start: string, end: string}>}|null  $hours
     * @param  list<int>  $days
     * @param  list<string>  $holidays
     */
    private function calendar(?array $hours = null, array $days = [1, 2, 3, 4, 5], array $holidays = []): BusinessCalendar
    {
        return new BusinessCalendar($days, $holidays, $hours);
    }
}
