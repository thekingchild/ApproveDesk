<?php

namespace Tests\Feature\Performance;

use App\Models\Submission;
use App\Models\SubmissionWatcher;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Services\AnalyticsService;
use App\Services\ApproverWorkbench;
use App\Services\SubmissionSearchService;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-PERF-07 (search), RV-PERF-08 (inbox), RV-PERF-09 (request page
 * navigation) and RV-PERF-10 (analytics).
 */
class PagePerformanceRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->approver = User::factory()->create()->assignRole('approver');
        $this->other = User::factory()->create()->assignRole('approver');
    }

    public function test_search_cost_does_not_grow_with_requests_the_user_cannot_see(): void
    {
        $requester = User::factory()->create()->assignRole('submitter');
        WorkflowTemplate::factory()->default()->sequential([$this->other])->create();
        $mine = $this->startRequest(['submitter_id' => $requester->id, 'title' => 'Report printer']);

        $queries = function (int $hidden) use ($requester): array {
            Submission::factory()->count($hidden)->create(['title' => 'Report quarterly']);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $results = app(SubmissionSearchService::class)->search($requester, 're');
            DB::disableQueryLog();

            return [$results->pluck('id')->all(), count(DB::getQueryLog())];
        };

        app(SubmissionSearchService::class)->search($requester, 're'); // warm the permission cache
        [$few, $fewQueries] = $queries(3);
        [$many, $manyQueries] = $queries(30);

        $this->assertSame([$mine->id], $few);
        $this->assertSame([$mine->id], $many);
        $this->assertSame($fewQueries, $manyQueries);
    }

    public function test_search_still_finds_requests_visible_through_followers_and_steps(): void
    {
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        $assigned = $this->startRequest(['title' => 'Budget assigned']);
        $followed = Submission::factory()->create(['title' => 'Budget followed']);
        SubmissionWatcher::query()->create(['submission_id' => $followed->id, 'user_id' => $this->other->id, 'added_by_id' => $followed->submitter_id]);
        $selfFollowed = Submission::factory()->create(['title' => 'Budget self-followed']);
        SubmissionWatcher::query()->create(['submission_id' => $selfFollowed->id, 'user_id' => $this->other->id, 'added_by_id' => $this->other->id]);

        $this->assertSame([$assigned->id], app(SubmissionSearchService::class)->search($this->approver, 'budget')->pluck('id')->all());
        $this->assertSame([$followed->id], app(SubmissionSearchService::class)->search($this->other, 'budget')->pluck('id')->all());
    }

    public function test_workbench_does_not_load_workflows_the_approver_has_no_part_in(): void
    {
        WorkflowTemplate::factory()->default()->sequential([$this->other])->create();
        foreach (range(1, 5) as $ignored) {
            $this->startRequest();
        }
        WorkflowTemplate::query()->update(['is_default' => false]);
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        $mine = $this->startRequest();

        $loaded = 0;
        Event::listen('eloquent.retrieved: '.WorkflowInstance::class, function () use (&$loaded): void {
            $loaded++;
        });
        $items = app(ApproverWorkbench::class)->itemsFor($this->approver);

        $this->assertSame([$mine->id], collect($items)->map(fn (array $item): int => $item['instance']->submission_id)->all());
        $this->assertSame(1, $loaded);
    }

    public function test_inbox_shows_one_page_at_a_time(): void
    {
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        foreach (range(1, 27) as $ignored) {
            $this->startRequest();
        }

        $inbox = Livewire::actingAs($this->approver)->test('pages::approvals.inbox');
        $this->assertCount(25, $inbox->instance()->pageItems);
        $this->assertSame(27, $inbox->instance()->viewCounts['needs_decision']);

        $inbox->call('nextPage');
        $this->assertCount(2, $inbox->instance()->pageItems);
    }

    public function test_request_page_links_to_the_queue_and_resolves_neighbours_on_click(): void
    {
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        $first = $this->startRequest();
        $second = $this->startRequest();
        $queue = collect(app(ApproverWorkbench::class)->itemsFor($this->approver))->pluck('instance.submission_id')->all();

        Livewire::actingAs($this->approver)
            ->test('pages::submissions.show', ['submission' => $first])
            ->assertSee(route('approvals.queue', [$first, 'next']), false);

        $this->actingAs($this->approver)
            ->get(route('approvals.queue', [$queue[0], 'next']))
            ->assertRedirect(route('submissions.show', $queue[1]));
        $this->actingAs($this->approver)
            ->get(route('approvals.queue', [$queue[1], 'next']))
            ->assertRedirect(route('approvals.inbox'))
            ->assertSessionHas('status');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $queue);
    }

    public function test_analytics_are_cached_until_refreshed(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        $this->startRequest();
        $analytics = app(AnalyticsService::class);

        $this->assertSame(1, $analytics->dashboard(30, $admin)['summary']['started']);
        $this->startRequest();
        $this->assertSame(1, $analytics->dashboard(30, $admin)['summary']['started']);

        Livewire::actingAs($admin)
            ->test('pages::admin.analytics')
            ->call('refresh')
            ->assertSee('Figures as of');
        $this->assertSame(2, $analytics->dashboard(30, $admin)['summary']['started']);
    }

    /** @param array<string, mixed> $attributes */
    private function startRequest(array $attributes = []): Submission
    {
        $submission = Submission::factory()->create($attributes);
        app(WorkflowEngine::class)->initialize($submission);

        return $submission->fresh();
    }
}
