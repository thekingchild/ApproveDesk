<?php

namespace Database\Seeders;

use App\Enums\WorkflowActionType;
use App\Models\Comment;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;

/**
 * Local-only large dataset for performance review (REVIEW_PLAN Wave 0).
 * Never part of DatabaseSeeder. Every workflow transition goes through
 * WorkflowEngine so the audit trail stays realistic.
 *
 *   PERF_USERS=500 PERF_SUBMISSIONS=20000 php artisan db:seed --class=PerformanceSeeder
 */
class PerformanceSeeder extends Seeder
{
    public function run(WorkflowEngine $engine): void
    {
        if (! app()->environment('local')) {
            $this->command->error('PerformanceSeeder only runs in the local environment.');

            return;
        }

        $userCount = max(10, (int) (getenv('PERF_USERS') ?: 500));
        $submissionCount = max(1, (int) (getenv('PERF_SUBMISSIONS') ?: 20000));

        Notification::fake();
        config(['queue.default' => 'sync']);

        $departments = Department::factory()->count(8)->create();
        $approvers = User::factory()->count(6)->create()->each->assignRole('approver');
        $submitters = User::factory()->count($userCount)->create([
            'department_id' => fn () => $departments->random()->id,
        ])->each->assignRole('submitter');
        User::factory()->create(['email' => 'perf-admin@example.com'])->assignRole('admin');

        // Route every seeded submission through this template, not the demo default.
        WorkflowTemplate::query()->where('is_default', true)->update(['is_default' => false]);
        WorkflowTemplate::factory()->default()->sequential($approvers->take(3)->all())->create([
            'name' => 'Performance three-level approval',
        ]);

        $bar = $this->command->getOutput()->createProgressBar($submissionCount);

        for ($i = 0; $i < $submissionCount; $i++) {
            $submitter = $submitters->random();
            $submission = Submission::factory()->create([
                'submitter_id' => $submitter->id,
                'department_id' => $submitter->department_id,
                'created_at' => now()->subMinutes(random_int(0, 60 * 24 * 365)),
            ]);
            $instance = $engine->initialize($submission);

            // Advance a realistic spread: some pending, most decided.
            $steps = random_int(0, 3);
            foreach ($approvers->take($steps) as $level => $approver) {
                $action = ($level === $steps - 1 && random_int(1, 10) === 1)
                    ? WorkflowActionType::Reject
                    : WorkflowActionType::Approve;
                $instance = $engine->processAction($instance->fresh(), $approver, $action, 'Reviewed.');
                if ($action === WorkflowActionType::Reject) {
                    break;
                }
            }

            if ($i % 4 === 0) {
                Comment::factory()->count(random_int(1, 5))->create([
                    'commentable_id' => $submission->id,
                    'commentable_type' => Submission::class,
                    'author_id' => $submitter->id,
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
    }
}
