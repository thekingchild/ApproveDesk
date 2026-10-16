<?php

namespace Tests\Feature\Operations;

use App\Jobs\ApplyOutOfOfficeFallbacksJob;
use App\Jobs\ApplyRetentionJob;
use App\Jobs\ProcessEscalation;
use App\Jobs\RetryNotificationDeliveries;
use App\Jobs\SendNotificationDigests;
use App\Models\NotificationDelivery;
use App\Models\OpsSnapshot;
use App\Models\OutOfOffice;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Audit\EvidencePackBuilder;
use App\Services\Collaboration\OutOfOfficeFallback;
use App\Services\Notifications\NotificationDeliveryRecorder;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * RV-OPS-01 (queue worker), RV-OPS-02 (one copy of each scheduled job),
 * RV-OPS-04 (ops_snapshots pruning), RV-PERF-11 (out-of-office sweep) and
 * RV-PERF-12 (evidence pack deliveries).
 */
class ScheduledWorkTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_worker_exits_within_a_minute_and_its_lock_cannot_go_stale_for_a_day(): void
    {
        $worker = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'queue:work'));

        $this->assertNotNull($worker);
        $this->assertStringContainsString('--stop-when-empty', $worker->command);
        $this->assertMatchesRegularExpression('/--max-time=(\d+)/', $worker->command);
        preg_match('/--max-time=(\d+)/', $worker->command, $maxTime);
        $this->assertLessThan(60, (int) $maxTime[1]);
        $this->assertTrue($worker->runInBackground);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertLessThanOrEqual(5, $worker->expiresAt);

        preg_match('/--timeout=(\d+)/', $worker->command, $timeout);
        $this->assertGreaterThan((int) $timeout[1], (int) config('queue.connections.database.retry_after'));
    }

    public function test_scheduled_jobs_cannot_pile_up_in_the_queue(): void
    {
        Queue::fake();

        foreach ([SendNotificationDigests::class, ProcessEscalation::class, ApplyOutOfOfficeFallbacksJob::class, RetryNotificationDeliveries::class, ApplyRetentionJob::class] as $job) {
            $this->assertContains(ShouldBeUnique::class, class_implements($job), $job);
            $job::dispatch();
            $job::dispatch();
            Queue::assertPushed($job, 1);
        }
    }

    public function test_old_ops_snapshots_are_pruned(): void
    {
        OpsSnapshot::query()->create(['recorded_at' => now()->subDays(OpsSnapshot::KEEP_DAYS + 1)]);
        $recent = OpsSnapshot::query()->create(['recorded_at' => now()->subDay()]);

        $this->artisan('model:prune', ['--model' => [OpsSnapshot::class]])->assertSuccessful();

        $this->assertSame([$recent->id], OpsSnapshot::query()->pluck('id')->all());
    }

    public function test_out_of_office_sweep_does_not_grow_with_unrelated_workflows(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $away = User::factory()->create()->assignRole('approver');
        $fallback = User::factory()->create()->assignRole('approver');
        $other = User::factory()->create()->assignRole('approver');
        $mine = WorkflowTemplate::factory()->default()->sequential([$away])->create();
        $engine = app(WorkflowEngine::class);
        $engine->initialize(Submission::factory()->create());
        $engine->initialize(Submission::factory()->create());
        $mine->update(['is_default' => false]);
        WorkflowTemplate::factory()->default()->sequential([$other])->create();
        OutOfOffice::factory()->create(['user_id' => $away->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'fallback_user_id' => $fallback->id]);

        $queries = function (int $unrelated) use ($engine): array {
            for ($i = 0; $i < $unrelated; $i++) {
                $engine->initialize(Submission::factory()->create());
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $applied = app(OutOfOfficeFallback::class)->applyActiveSchedules();
            DB::disableQueryLog();

            return [$applied, count(DB::getQueryLog())];
        };

        [$appliedFirst, $few] = $queries(3);
        $this->assertSame(2, $appliedFirst);

        [$appliedAgain, $many] = $queries(20);
        $this->assertSame(0, $appliedAgain, 'steps already handed over are not delegated twice');
        $this->assertLessThanOrEqual($few, $many);
    }

    public function test_evidence_pack_lists_only_this_requests_deliveries(): void
    {
        $user = User::factory()->create();
        $submission = Submission::factory()->create();
        $other = Submission::factory()->create();
        $recorder = app(NotificationDeliveryRecorder::class);
        $mine = $recorder->start($user, 'action_required', 'database', ['submission_id' => $submission->id]);
        $recorder->start($user, 'action_required', 'database', ['submission_id' => $other->id]);
        $recorder->start($user, 'suspicious_login', 'database');

        $this->assertSame($submission->id, $mine->fresh()->submission_id);

        $notifications = json_decode(app(EvidencePackBuilder::class)->files($submission)['notifications.json'], true);
        $this->assertSame([$mine->id], array_column($notifications, 'id'));
        $this->assertSame(3, NotificationDelivery::query()->count());
    }
}
