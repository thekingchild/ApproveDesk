<?php

namespace Tests\Feature\Operations;

use App\Actions\Audit\PruneEvidencePackFilesAction;
use App\Models\ApiIdempotencyKey;
use App\Models\EvidencePack;
use App\Models\InAppNotification;
use App\Models\LegalHold;
use App\Models\NotificationDelivery;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RV-OPS-05: read in-app notifications, notification deliveries, failed jobs
 * and evidence-pack zips are pruned; audit evidence (unread notifications,
 * deliveries about held requests, pack records, the activity log) is kept.
 */
class HousekeepingPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_housekeeping_is_scheduled_and_the_activity_log_is_not_pruned(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

        $prune = $commands->first(fn (string $command): bool => str_contains($command, 'model:prune'));
        $this->assertNotNull($prune);
        $this->assertStringContainsString(NotificationDelivery::class, $prune);
        $this->assertStringContainsString(InAppNotification::class, $prune);
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'queue:prune-failed') && str_contains($command, '--hours=720')));
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'evidence:prune-files')));
        $this->assertFalse($commands->contains(fn (string $command): bool => str_contains($command, 'activitylog:clean')));
    }

    public function test_only_old_read_in_app_notifications_are_pruned(): void
    {
        $user = User::factory()->create();
        $oldRead = $this->notification($user, readAt: now()->subDays(InAppNotification::KEEP_READ_DAYS + 1));
        $oldUnread = $this->notification($user, readAt: null, createdAt: now()->subYears(2));
        $recentRead = $this->notification($user, readAt: now()->subDays(10));

        $this->artisan('model:prune', ['--model' => [InAppNotification::class]])->assertSuccessful();

        $remaining = DB::table('notifications')->pluck('id')->all();
        $this->assertNotContains($oldRead, $remaining);
        $this->assertContains($oldUnread, $remaining);
        $this->assertContains($recentRead, $remaining);
    }

    public function test_old_deliveries_are_pruned_except_for_requests_under_legal_hold(): void
    {
        $user = User::factory()->create();
        $held = Submission::factory()->create();
        LegalHold::query()->create(['submission_id' => $held->id, 'placed_by' => $user->id, 'reason' => 'Litigation', 'placed_at' => now()]);
        $free = Submission::factory()->create();

        $old = now()->subDays(NotificationDelivery::KEEP_DAYS + 1);
        $oldFree = $this->delivery($user, $free->id, $old);
        $oldUnlinked = $this->delivery($user, null, $old);
        $oldHeld = $this->delivery($user, $held->id, $old);
        $recent = $this->delivery($user, $free->id, now()->subDays(5));

        $this->artisan('model:prune', ['--model' => [NotificationDelivery::class]])->assertSuccessful();

        $remaining = NotificationDelivery::query()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$oldHeld, $recent], $remaining);
        $this->assertNotContains($oldFree, $remaining);
        $this->assertNotContains($oldUnlinked, $remaining);
    }

    public function test_old_evidence_pack_zips_are_deleted_but_their_records_are_kept(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('evidence/1/old.zip', 'old');
        $disk->put('evidence/2/recent.zip', 'recent');
        $disk->put('evidence/1/notes.txt', 'not a pack');
        touch($disk->path('evidence/1/old.zip'), now()->subDays(PruneEvidencePackFilesAction::KEEP_DAYS + 1)->getTimestamp());
        touch($disk->path('evidence/1/notes.txt'), now()->subDays(PruneEvidencePackFilesAction::KEEP_DAYS + 1)->getTimestamp());
        $pack = EvidencePack::factory()->create(['stored_path' => 'evidence/1/old.zip', 'disk' => 'local']);

        $this->artisan('evidence:prune-files')->expectsOutputToContain('Deleted 1')->assertSuccessful();

        $disk->assertMissing('evidence/1/old.zip');
        $disk->assertExists('evidence/2/recent.zip');
        $disk->assertExists('evidence/1/notes.txt');
        $this->assertNotNull($pack->fresh());
    }

    public function test_empty_evidence_folders_are_removed(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('evidence/7/old.zip', 'old');
        touch($disk->path('evidence/7/old.zip'), now()->subDays(PruneEvidencePackFilesAction::KEEP_DAYS + 1)->getTimestamp());

        $this->assertSame(1, app(PruneEvidencePackFilesAction::class)->execute());

        $this->assertNotContains('evidence/7', $disk->directories('evidence'));
    }

    private function notification(User $user, mixed $readAt, mixed $createdAt = null): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\ActionRequiredNotification',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => '{}',
            'read_at' => $readAt,
            'created_at' => $createdAt ?? $readAt ?? now(),
            'updated_at' => $createdAt ?? $readAt ?? now(),
        ]);

        return $id;
    }

    private function delivery(User $user, ?int $submissionId, mixed $createdAt): int
    {
        return (int) DB::table('notification_deliveries')->insertGetId([
            'user_id' => $user->id,
            'submission_id' => $submissionId,
            'kind' => 'action_required',
            'channel' => 'slack',
            'status' => 'sent',
            'attempts' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public function test_idempotency_keys_older_than_a_week_are_pruned(): void
    {
        $user = User::factory()->create();
        $old = ApiIdempotencyKey::query()->create(['user_id' => $user->id, 'key' => 'old', 'method' => 'POST', 'path' => 'api/v1/submissions', 'request_hash' => 'a', 'status_code' => 201]);
        $old->forceFill(['created_at' => now()->subDays(ApiIdempotencyKey::KEEP_DAYS + 1)])->save();
        $recent = ApiIdempotencyKey::query()->create(['user_id' => $user->id, 'key' => 'new', 'method' => 'POST', 'path' => 'api/v1/submissions', 'request_hash' => 'b', 'status_code' => 201]);

        $this->artisan('model:prune', ['--model' => [ApiIdempotencyKey::class]])->assertSuccessful();

        $this->assertSame([$recent->id], ApiIdempotencyKey::query()->pluck('id')->all());
    }
}
