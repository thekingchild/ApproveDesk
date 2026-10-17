<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Submission;
use App\Models\User;
use App\Support\LocalTime;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/** RV-ARC-17: times are shown in the viewer's own zone, remembered between visits. */
class ViewerTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-16 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rv_arc_17_the_request_page_shows_comment_times_in_the_remembered_zone(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        Comment::factory()->create([
            'commentable_type' => Submission::class,
            'commentable_id' => $submission->id,
            'author_id' => $submitter->id,
            'body' => 'Checked the figures.',
        ]);

        // 08:00 UTC is 17:00 in Tokyo.
        Livewire::withCookie(LocalTime::COOKIE, 'Asia/Tokyo')
            ->actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $submission])
            ->assertSee('16 Oct 2026 17:00')
            ->assertDontSee('16 Oct 2026 08:00');
    }

    public function test_rv_arc_17_without_a_valid_remembered_zone_the_installation_zone_is_used(): void
    {
        config(['app.timezone' => 'UTC']);
        $at = Carbon::parse('2026-10-16 08:00:00', 'UTC');

        $this->assertSame('16 Oct 2026 08:00', LocalTime::forViewer($at));

        request()->cookies->set(LocalTime::COOKIE, 'Not/AZone');
        $this->assertSame('UTC', LocalTime::viewerTimezone());

        request()->cookies->set(LocalTime::COOKIE, 'America/New_York');
        $this->assertSame('16 Oct 2026 04:00', LocalTime::forViewer($at));
        $this->assertSame('2026-10-16T04:00', LocalTime::inputValue($at), 'datetime-local needs the T separator.');
    }

    public function test_rv_arc_17_forms_read_input_in_the_remembered_zone_before_the_browser_reports_it(): void
    {
        request()->cookies->set(LocalTime::COOKIE, 'Asia/Tokyo');

        $parsed = LocalTime::parse('2026-10-16T17:00', '');

        $this->assertTrue($parsed->equalTo(Carbon::parse('2026-10-16 08:00:00', 'UTC')));
        $this->assertSame('Asia/Tokyo', LocalTime::label(''));
    }

    public function test_rv_arc_17_the_time_component_marks_up_the_exact_moment(): void
    {
        request()->cookies->set(LocalTime::COOKIE, 'Asia/Tokyo');
        $at = Carbon::parse('2026-10-16 08:00:00', 'UTC');

        $html = Blade::render('<x-time :at="$at" format="date" />', ['at' => $at]);
        $this->assertStringContainsString('datetime="2026-10-16T08:00:00+00:00"', $html);
        $this->assertStringContainsString('title="Fri 16 Oct 2026, 17:00 (Asia/Tokyo)"', $html);
        $this->assertStringContainsString('>16 Oct 2026</time>', $html);

        $this->assertStringContainsString('—', Blade::render('<x-time :at="null" />'));
    }

    public function test_rv_arc_17_the_zone_cookie_is_not_encrypted(): void
    {
        $user = User::factory()->create()->assignRole('submitter');

        // A plain (unencrypted) cookie, as the browser script writes it, survives the middleware.
        $this->withUnencryptedCookie(LocalTime::COOKIE, 'Asia/Tokyo')
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame('Asia/Tokyo', LocalTime::viewerTimezone());
    }
}
