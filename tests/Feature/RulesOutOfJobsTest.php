<?php

namespace Tests\Feature;

use App\Actions\Audit\UpdateRetentionSettingsAction;
use App\Enums\NotificationFrequency;
use App\Models\LoginEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\NewDeviceDetector;
use App\Services\Notifications\DigestSchedule;
use App\Support\RetentionSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** RV-ARC-14: retention, new-device and digest rules live in services, not jobs and listeners. */
class RulesOutOfJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rv_arc_14_retention_settings_have_one_set_of_defaults_and_bounds(): void
    {
        $this->assertSame(90, RetentionSettings::draftDays());
        $this->assertSame(2555, RetentionSettings::completedDays());

        Setting::put(RetentionSettings::DRAFT_DAYS, '0');
        Setting::put(RetentionSettings::COMPLETED_DAYS, '99999');
        $this->assertSame(1, RetentionSettings::draftDays());
        $this->assertSame(RetentionSettings::MAX_COMPLETED_DAYS, RetentionSettings::completedDays());
    }

    public function test_rv_arc_14_changing_retention_is_authorized_validated_and_logged(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        app(UpdateRetentionSettingsAction::class)->execute($admin, 30, '365');

        $this->assertSame(30, RetentionSettings::draftDays());
        $this->assertSame(365, RetentionSettings::completedDays());
        $activity = Activity::query()->where('event', 'retention-updated')->sole();
        $this->assertSame(['draft_days' => 90, 'completed_days' => 2555], $activity->properties['old']);
        $this->assertSame(['draft_days' => 30, 'completed_days' => 365], $activity->properties['attributes']);
        $this->assertTrue($activity->causer->is($admin));

        try {
            app(UpdateRetentionSettingsAction::class)->execute($admin, 0, 365);
            $this->fail('Zero days must be refused.');
        } catch (ValidationException) {
            $this->assertSame(30, RetentionSettings::draftDays());
        }

        $this->expectException(AuthorizationException::class);
        app(UpdateRetentionSettingsAction::class)->execute(User::factory()->create()->assignRole('submitter'), 10, 10);
    }

    public function test_rv_arc_14_the_records_page_saves_through_the_action(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::admin.records')
            ->assertSet('draftDays', '90')
            ->set('draftDays', '45')
            ->set('completedDays', '400')
            ->call('saveRetention')
            ->assertHasNoErrors();

        $this->assertSame(45, RetentionSettings::draftDays());
        $this->assertTrue(Activity::query()->where('event', 'retention-updated')->exists());
    }

    public function test_rv_arc_14_new_device_detection(): void
    {
        $user = User::factory()->create();
        $detector = app(NewDeviceDetector::class);

        $first = $detector->check($user, '203.0.113.10', 'Browser/1');
        $this->assertFalse($first->newDevice, 'A first sign-in has nothing to compare with.');
        $this->assertFalse($first->duplicate);

        LoginEvent::query()->create(['user_id' => $user->id, 'fingerprint' => $first->fingerprint, 'created_at' => now()->subHour()]);

        $this->assertFalse($detector->check($user, '203.0.113.10', 'Browser/1')->newDevice, 'A known device.');
        $this->assertTrue($detector->check($user, '198.51.100.20', 'Other/2')->newDevice, 'A device never seen before.');

        LoginEvent::query()->create(['user_id' => $user->id, 'fingerprint' => $first->fingerprint, 'created_at' => now()->subSeconds(10)]);
        $this->assertTrue($detector->check($user, '203.0.113.10', 'Browser/1')->duplicate, 'The same sign-in reported twice.');
    }

    public function test_rv_arc_14_digest_schedule_uses_calendar_days(): void
    {
        $schedule = app(DigestSchedule::class);
        $now = Carbon::parse('2026-10-17 06:59:58', 'UTC');
        $daily = User::factory()->make([
            'notification_frequency' => NotificationFrequency::Daily,
            'last_notification_digest_at' => Carbon::parse('2026-10-16 07:00:00', 'UTC'),
        ]);

        $this->assertTrue($schedule->isDue($daily, $now), 'Two seconds early is still the next day (RV-BUG-18).');
        $this->assertFalse($schedule->isDue($daily, Carbon::parse('2026-10-16 23:00:00', 'UTC')));
        $this->assertTrue($schedule->coversSince($daily, $now)->equalTo($daily->last_notification_digest_at));

        $weekly = User::factory()->make([
            'notification_frequency' => NotificationFrequency::Weekly,
            'last_notification_digest_at' => Carbon::parse('2026-10-12 07:00:00', 'UTC'),
        ]);
        $this->assertFalse($schedule->isDue($weekly, $now));
        $this->assertTrue($schedule->isDue($weekly, Carbon::parse('2026-10-19 06:00:00', 'UTC')));

        $never = User::factory()->make(['notification_frequency' => NotificationFrequency::Immediate, 'last_notification_digest_at' => null]);
        $this->assertFalse($schedule->isDue($never, $now));

        $firstDigest = User::factory()->make(['notification_frequency' => NotificationFrequency::Weekly, 'last_notification_digest_at' => null]);
        $this->assertTrue($schedule->isDue($firstDigest, $now));
        $this->assertTrue($schedule->coversSince($firstDigest, $now)->equalTo($now->copy()->subDays(7)));
    }
}
