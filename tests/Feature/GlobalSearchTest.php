<?php

namespace Tests\Feature;

use App\Livewire\GlobalSearch;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\SubmissionSearchService;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_search_matches_reference_title_requester_status_and_metadata(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $requester = User::factory()->create(['name' => 'Amina Okafor']);
        $submission = Submission::factory()->for($requester, 'submitter')->create([
            'title' => 'Regional procurement renewal',
            'status' => 'in_review',
            'metadata' => ['vendor' => 'Northstar Systems'],
        ]);

        $service = app(SubmissionSearchService::class);

        foreach ([$submission->reference_number, 'procurement', 'Amina Okafor', 'in review', 'Northstar'] as $query) {
            $this->assertTrue($service->search($admin, $query)->contains($submission), "Search did not match [{$query}].");
        }
    }

    public function test_search_never_returns_a_submission_denied_by_policy(): void
    {
        $owner = User::factory()->create()->assignRole('submitter');
        $other = User::factory()->create()->assignRole('submitter');
        $visible = Submission::factory()->for($owner, 'submitter')->create(['title' => 'Visible renewal']);
        $hidden = Submission::factory()->for($other, 'submitter')->create(['title' => 'Hidden renewal']);

        Livewire::actingAs($owner)
            ->test(GlobalSearch::class)
            ->set('query', 'renewal')
            ->assertSee($visible->title)
            ->assertDontSee($hidden->title);
    }

    public function test_current_approver_can_find_an_assigned_submission(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        $submitter = User::factory()->create()->assignRole('submitter');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create([
            'title' => 'Assigned security review',
        ]);
        app(WorkflowEngine::class)->initialize($submission);

        $this->assertTrue(
            app(SubmissionSearchService::class)->search($approver, 'security review')->contains($submission),
        );
    }
}
