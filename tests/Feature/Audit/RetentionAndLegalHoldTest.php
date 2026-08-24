<?php

namespace Tests\Feature\Audit;

use App\Actions\Audit\PlaceLegalHoldAction;
use App\Actions\Audit\RequestDispositionAction;
use App\Actions\Audit\ReviewDispositionAction;
use App\Enums\DispositionStatus;
use App\Enums\SubmissionStatus;
use App\Jobs\ApplyRetentionJob;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RetentionAndLegalHoldTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
        Setting::put('retention.draft_days', '30');
        Setting::put('retention.completed_days', '90');
    }

    public function test_old_drafts_without_a_hold_are_soft_deleted(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        $old = Submission::factory()->create([
            'status' => SubmissionStatus::Draft,
            'created_at' => now()->subDays(40),
        ]);
        $recent = Submission::factory()->create([
            'status' => SubmissionStatus::Draft,
            'created_at' => now()->subDays(5),
        ]);

        (new ApplyRetentionJob)->handle();

        $this->assertSoftDeleted($old);
        $this->assertNotSoftDeleted($recent);
    }

    public function test_legal_hold_blocks_purge_even_when_retention_has_elapsed(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        $held = Submission::factory()->create([
            'status' => SubmissionStatus::Draft,
            'created_at' => now()->subDays(40),
        ]);
        app(PlaceLegalHoldAction::class)->execute($this->admin, $held, 'Litigation');

        (new ApplyRetentionJob)->handle();

        $this->assertNotSoftDeleted($held);
    }

    public function test_completed_request_needs_an_approved_disposition_before_purge(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        $ready = Submission::factory()->create([
            'status' => SubmissionStatus::Approved,
            'created_at' => now()->subDays(120),
            'updated_at' => now()->subDays(120),
        ]);
        $waiting = Submission::factory()->create([
            'status' => SubmissionStatus::Approved,
            'created_at' => now()->subDays(120),
            'updated_at' => now()->subDays(120),
        ]);

        $review = app(RequestDispositionAction::class)->execute($this->admin, $ready, 'Past retention');
        app(ReviewDispositionAction::class)->execute($this->admin, $review, DispositionStatus::Approved, 'OK to close');

        (new ApplyRetentionJob)->handle();

        $this->assertSoftDeleted($ready);
        $this->assertNotSoftDeleted($waiting);
    }
}
