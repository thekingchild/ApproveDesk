<?php

namespace Tests\Feature\Announcements;

use App\Actions\Announcements\AcknowledgeAnnouncementAction;
use App\Actions\Announcements\ArchiveAnnouncementAction;
use App\Actions\Announcements\PublishAnnouncementAction;
use App\Actions\Announcements\SaveAnnouncementAction;
use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementDisplayMode;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Announcements\AnnouncementBannerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AnnouncementDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_announcement_schema_models_casts_and_relationships_are_portable(): void
    {
        $creator = User::factory()->create();
        $announcement = Announcement::factory()->create([
            'creator_id' => $creator->id,
            'audience' => AnnouncementAudience::All,
            'display_mode' => AnnouncementDisplayMode::Sticky,
            'status' => AnnouncementStatus::Draft,
        ]);
        $receipt = AnnouncementReceipt::factory()->create([
            'announcement_id' => $announcement->id,
            'user_id' => $creator->id,
        ]);

        $this->assertTrue($announcement->creator->is($creator));
        $this->assertTrue($receipt->announcement->is($announcement));
        $this->assertSame(AnnouncementAudience::All, $announcement->audience);
        $this->assertSame(AnnouncementDisplayMode::Sticky, $announcement->display_mode);
        $this->assertSame(AnnouncementStatus::Draft, $announcement->status);
        $this->assertCount(1, $announcement->receipts);
    }

    public function test_owner_save_sanitizes_and_non_owner_is_rejected(): void
    {
        $owner = $this->owner();
        $roleOnly = User::factory()->create()->assignRole('super-admin');

        $announcement = app(SaveAnnouncementAction::class)->execute(null, [
            'title' => 'Safety notice',
            'message' => '<p onclick="bad()">Safe <strong>text</strong></p><script>alert(1)</script>',
            'audience' => 'all',
            'role_ids' => [],
            'display_mode' => 'sticky',
        ], $owner);

        $this->assertSame('<p>Safe <strong>text</strong></p>', $announcement->message);

        $this->expectException(AuthorizationException::class);
        app(SaveAnnouncementAction::class)->execute($announcement, [
            'title' => 'Forged', 'message' => '<p>No</p>', 'audience' => 'all',
            'role_ids' => [], 'display_mode' => 'sticky',
        ], $roleOnly);
    }

    public function test_audience_modes_and_receipts_are_idempotent(): void
    {
        $owner = $this->owner();
        $target = User::factory()->create()->assignRole('approver');
        $other = User::factory()->create()->assignRole('submitter');
        $inactive = User::factory()->create(['is_active' => false])->assignRole('approver');
        $roleId = $target->roles()->firstOrFail()->id;

        $once = Announcement::factory()->published()->create([
            'creator_id' => $owner->id,
            'audience' => AnnouncementAudience::Roles,
            'role_ids' => [$roleId],
            'display_mode' => AnnouncementDisplayMode::ShowOnce,
        ]);
        $service = app(AnnouncementBannerService::class);

        $this->assertTrue($service->eligibleFor($target)->contains($once));
        $this->assertFalse($service->eligibleFor($other)->contains($once));
        $this->assertFalse($service->eligibleFor($inactive)->contains($once));
        $service->recordDisplayed($once, $target);
        $service->recordDisplayed($once, $target);
        $this->assertDatabaseCount('announcement_receipts', 1);
        $this->assertFalse($service->eligibleFor($target)->contains($once));

        $ack = Announcement::factory()->published()->create([
            'creator_id' => $owner->id,
            'audience' => AnnouncementAudience::All,
            'display_mode' => AnnouncementDisplayMode::Acknowledge,
        ]);
        $this->assertTrue($service->eligibleFor($target)->contains($ack));
        app(AcknowledgeAnnouncementAction::class)->execute($ack, $target);
        app(AcknowledgeAnnouncementAction::class)->execute($ack, $target);
        $this->assertFalse($service->eligibleFor($target)->contains($ack));
        $this->assertNotNull($ack->receipts()->whereBelongsTo($target)->firstOrFail()->acknowledged_at);
    }

    public function test_publish_archive_and_republish_reset_eligibility(): void
    {
        $owner = $this->owner();
        $user = User::factory()->create();
        $announcement = Announcement::factory()->create(['creator_id' => $owner->id, 'display_mode' => AnnouncementDisplayMode::ShowOnce]);

        app(PublishAnnouncementAction::class)->execute($announcement, $owner);
        app(AnnouncementBannerService::class)->recordDisplayed($announcement, $user);
        $this->assertFalse(app(AnnouncementBannerService::class)->eligibleFor($user)->contains($announcement));

        app(ArchiveAnnouncementAction::class)->execute($announcement, $owner);
        $this->assertSame(AnnouncementStatus::Archived, $announcement->fresh()->status);
        app(PublishAnnouncementAction::class)->execute($announcement, $owner);
        $this->assertTrue(app(AnnouncementBannerService::class)->eligibleFor($user)->contains($announcement));
    }

    public function test_acknowledgement_requires_a_current_published_audience_match(): void
    {
        $owner = $this->owner();
        $target = User::factory()->create()->assignRole('approver');
        $other = User::factory()->create()->assignRole('submitter');
        $roleId = $target->roles()->firstOrFail()->id;
        $announcement = Announcement::factory()->published()->create([
            'creator_id' => $owner->id,
            'audience' => AnnouncementAudience::Roles,
            'role_ids' => [$roleId],
            'display_mode' => AnnouncementDisplayMode::Acknowledge,
        ]);

        try {
            app(AcknowledgeAnnouncementAction::class)->execute($announcement, $other);
            $this->fail('Expected audience validation.');
        } catch (ValidationException $exception) {
            $this->assertSame('This announcement is not currently available to you.', $exception->errors()['announcement'][0]);
        }
        $this->assertDatabaseCount('announcement_receipts', 0);

        $announcement->update(['status' => AnnouncementStatus::Archived, 'archived_at' => now()]);
        $this->expectException(ValidationException::class);
        app(AcknowledgeAnnouncementAction::class)->execute($announcement->fresh(), $target);
    }

    public function test_duplicate_display_recording_is_atomic_and_preserves_the_first_timestamp(): void
    {
        $user = User::factory()->create();
        $announcement = Announcement::factory()->published()->create([
            'display_mode' => AnnouncementDisplayMode::ShowOnce,
        ]);
        AnnouncementReceipt::query()->create([
            'announcement_id' => $announcement->id,
            'user_id' => $user->id,
            'first_displayed_at' => null,
        ]);
        $service = app(AnnouncementBannerService::class);

        $first = $service->recordDisplayed($announcement, $user);
        $second = $service->recordDisplayed($announcement, $user);

        $this->assertDatabaseCount('announcement_receipts', 1);
        $this->assertNotNull($first->first_displayed_at);
        $this->assertTrue($first->first_displayed_at->equalTo($second->first_displayed_at));
    }

    public function test_editing_a_published_announcement_republishes_corrected_content_and_resets_receipts(): void
    {
        $owner = $this->owner();
        $user = User::factory()->create();
        $announcement = Announcement::factory()->published()->create([
            'creator_id' => $owner->id,
            'title' => 'Old title',
            'message' => '<p>Old copy</p>',
            'display_mode' => AnnouncementDisplayMode::ShowOnce,
            'published_at' => now()->subHour(),
        ]);
        app(AnnouncementBannerService::class)->recordDisplayed($announcement, $user);
        $oldPublishedAt = $announcement->published_at;

        $updated = app(SaveAnnouncementAction::class)->execute($announcement, [
            'title' => 'Corrected title',
            'message' => '<p>Corrected copy</p>',
            'audience' => 'all',
            'role_ids' => [],
            'display_mode' => 'show_once',
        ], $owner);

        $this->assertSame('Corrected title', $updated->title);
        $this->assertTrue($updated->published_at->greaterThan($oldPublishedAt));
        $this->assertDatabaseMissing('announcement_receipts', ['announcement_id' => $announcement->id]);
        $this->assertTrue(app(AnnouncementBannerService::class)->eligibleFor($user)->contains($updated));
    }

    private function owner(): User
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        return $owner;
    }

    public function test_stored_message_is_re_sanitized_for_display(): void
    {
        // Content saved by the pre-RV-SEC-03 sanitizer could still hold live markup.
        $announcement = Announcement::factory()->create();
        $announcement->forceFill(['message' => '<p>Notice</p><img src=x onerror=alert(1)>'])->saveQuietly();

        $html = $announcement->fresh()->safeMessage();

        $this->assertStringContainsString('<p>Notice</p>', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<img', $html);
    }
}
