<?php

namespace Tests\Feature\Performance;

use App\DTOs\WorkflowActionData;
use App\Enums\WorkflowActionType;
use App\Models\Comment;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Notifications\CommentPostedNotification;
use App\Services\WorkflowEngine;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RV-TEST-03: key pages must not issue more queries as rows grow (no N+1).
 * Each page is rendered against a small and a larger dataset; the query
 * counts must match.
 */
class QueryScalingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $firstApprover;

    private User $secondApprover;

    private User $requester;

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return [
            'admin dashboard' => ['admin-dashboard'],
            'approver dashboard' => ['approver-dashboard'],
            'requester dashboard' => ['requester-dashboard'],
            'approvals inbox' => ['inbox'],
            'my submissions' => ['submissions'],
            'all submissions' => ['all-submissions'],
            'submission detail' => ['show'],
            'analytics' => ['analytics'],
            'notifications' => ['notifications'],
        ];
    }

    #[DataProvider('pages')]
    public function test_query_count_does_not_grow_with_rows(string $page): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->firstApprover = User::factory()->create()->assignRole('approver');
        $this->secondApprover = User::factory()->create()->assignRole('approver');
        $this->requester = User::factory()->create()->assignRole('submitter');
        WorkflowTemplate::factory()->default()->sequential([$this->firstApprover, $this->secondApprover])->create();

        $this->grow(3);
        $small = $this->queriesFor($page);
        $this->grow(9);
        $large = $this->queriesFor($page);

        $this->assertSame($small, $large, "{$page}: {$small} queries with a small dataset, {$large} with a larger one.");
    }

    private function grow(int $by): void
    {
        $engine = app(WorkflowEngine::class);
        $target = Submission::query()->oldest('id')->first();

        for ($i = 0; $i < $by; $i++) {
            $submission = Submission::factory()->create(['submitter_id' => $this->requester->id]);
            $instance = $engine->initialize($submission);
            if ($i % 2 === 0) {
                $engine->processAction($instance->fresh(), new WorkflowActionData($this->firstApprover, WorkflowActionType::Approve, 'Looks fine.'));
            }
            $target ??= $submission;

            $comment = Comment::factory()->create([
                'commentable_id' => $target->id,
                'commentable_type' => Submission::class,
                'author_id' => $this->requester->id,
            ]);
            $this->secondApprover->notify(new CommentPostedNotification($submission, $comment));
        }
    }

    private function queriesFor(string $page): int
    {
        [$user, $url] = match ($page) {
            'admin-dashboard' => [$this->admin, route('dashboard')],
            'approver-dashboard' => [$this->secondApprover, route('dashboard')],
            'requester-dashboard' => [$this->requester, route('dashboard')],
            'inbox' => [$this->secondApprover, route('approvals.inbox')],
            'submissions' => [$this->requester, route('submissions.index')],
            'all-submissions' => [$this->admin, route('submissions.index')],
            'show' => [$this->requester, route('submissions.show', Submission::query()->oldest('id')->firstOrFail())],
            'analytics' => [$this->admin, route('admin.analytics.index')],
            'notifications' => [$this->secondApprover, route('notifications.index')],
        };

        // Warm per-request caches (settings, permissions) so both runs compare like with like.
        $this->actingAs($user)->get($url)->assertOk();

        return $this->queriesDuring(fn () => $this->actingAs($user)->get($url)->assertOk());
    }

    private function queriesDuring(Closure $request): int
    {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (! str_contains($query->sql, '"sessions"')) {
                $queries++;
            }
        });

        $request();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $queries;
    }
}
