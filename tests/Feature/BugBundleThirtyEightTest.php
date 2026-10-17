<?php

namespace Tests\Feature;

use App\Actions\Backups\PromoteFilesBackupAction;
use App\Actions\Collaboration\EditCommentAction;
use App\Actions\Collaboration\PostCommentAction;
use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Enums\SubmissionStatus;
use App\Models\BackupSnapshot;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\MentionedInCommentNotification;
use App\Services\Audit\RetentionPolicy;
use App\Services\ReferenceNumberGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

/** RV-BUG-38: the small bugs bundled in one finding. */
class BugBundleThirtyEightTest extends TestCase
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
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_mention_added_by_editing_a_comment_is_recorded_and_notified_once(): void
    {
        Notification::fake();
        $author = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($author, 'submitter')->create();
        $first = User::factory()->create()->assignRole('approver');
        $second = User::factory()->create()->assignRole('approver');

        $comment = app(PostCommentAction::class)->execute($submission, $author, 'Please check @{'.$first->id.'}');
        app(EditCommentAction::class)->execute($comment, $author, 'Please check @{'.$first->id.'} and @{'.$second->id.'}');

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $comment->mentions()->pluck('user_id')->all());
        Notification::assertSentToTimes($first, MentionedInCommentNotification::class, 1);
        Notification::assertSentToTimes($second, MentionedInCommentNotification::class, 1);
        $this->assertTrue($submission->isWatchedBy($second));
    }

    public function test_draft_retention_counts_from_the_last_edit(): void
    {
        Setting::put('retention.draft_days', '30');
        $stale = Submission::factory()->create(['status' => SubmissionStatus::Draft]);
        $stale->forceFill(['created_at' => now()->subDays(60), 'updated_at' => now()->subDays(45)])->saveQuietly();
        $active = Submission::factory()->create(['status' => SubmissionStatus::Draft]);
        $active->forceFill(['created_at' => now()->subDays(60), 'updated_at' => now()->subDay()])->saveQuietly();

        $due = app(RetentionPolicy::class)->draftsDue()->pluck('id')->all();

        $this->assertContains($stale->id, $due);
        $this->assertNotContains($active->id, $due, 'A draft edited yesterday must not be retired.');
    }

    public function test_a_failed_files_promote_leaves_no_holding_copy(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create()->assignRole('admin');
        $snapshot = BackupSnapshot::factory()->create([
            'kind' => BackupSnapshotKind::Files,
            'status' => BackupSnapshotStatus::Verified,
            'verified_at' => now(),
            'path' => 'backups/files/missing.zip',
        ]);

        try {
            app(PromoteFilesBackupAction::class)->execute($snapshot, $admin, (string) $snapshot->id);
            $this->fail('A missing snapshot file was promoted.');
        } catch (Throwable) {
            // Expected: the snapshot file is not on this copy.
        }

        $this->assertSame([], Storage::disk('local')->directories('restore-holding'));
    }

    public function test_an_id_made_at_the_turn_of_the_month_uses_one_period(): void
    {
        Setting::put('approvals.reference.month_pattern', 'mm');
        $readings = 0;
        $clock = function () use (&$readings): CarbonImmutable {
            return $readings++ === 0
                ? CarbonImmutable::parse('2026-09-30 23:59:59')
                : CarbonImmutable::parse('2026-10-01 00:00:00');
        };
        Carbon::setTestNow($clock);
        CarbonImmutable::setTestNow($clock);

        $reference = app(ReferenceNumberGenerator::class)->next();

        $this->assertStringStartsWith('SUB-2026-09-', $reference, 'The counter and the printed period must match.');
    }
}
