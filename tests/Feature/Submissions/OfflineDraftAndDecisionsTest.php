<?php

namespace Tests\Feature\Submissions;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OfflineDraftAndDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
    }

    public function test_create_page_exposes_offline_draft_hook_and_offline_banner(): void
    {
        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create')
            ->assertSee('data-offline-draft="submission-draft-new"', false)
            ->assertSee('Offline — changes are not being saved');
    }

    public function test_offline_draft_that_differs_from_the_saved_draft_is_a_conflict(): void
    {
        $draft = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
            'title' => 'Server title',
            'body' => 'Saved on the server',
            'type' => 'memo',
            'priority' => 'normal',
        ]);

        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create', ['submission' => $draft])
            ->call('reviewOfflineDraft', [
                'title' => 'Offline title',
                'body' => 'written on the train',
                'type' => 'memo',
                'priority' => 'normal',
                'due_date' => '',
            ])
            ->assertSet('offlineDraftConflict', true)
            ->assertSee('data-test="offline-draft-conflict"', false)
            ->call('keepOfflineDraft')
            ->assertSet('title', 'Offline title')
            ->assertSet('body', 'written on the train')
            ->assertSet('offlineDraftConflict', false);
    }

    public function test_matching_offline_draft_is_not_a_conflict_and_keep_saved_discards_it(): void
    {
        $draft = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
            'title' => 'Server title',
            'body' => 'Saved on the server',
            'type' => 'memo',
            'priority' => 'normal',
        ]);

        $component = Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create', ['submission' => $draft]);

        $component->call('reviewOfflineDraft', [
            'title' => 'Server title',
            'body' => 'Saved on the server',
            'type' => 'memo',
            'priority' => 'normal',
            'due_date' => '',
        ])->assertSet('offlineDraftConflict', false);

        $component->call('reviewOfflineDraft', [
            'title' => 'Stale offline copy',
            'body' => 'Saved on the server',
            'type' => 'memo',
            'priority' => 'normal',
            'due_date' => '',
        ])
            ->assertSet('offlineDraftConflict', true)
            ->call('keepServerDraft')
            ->assertSet('title', 'Server title')
            ->assertSet('offlineDraftConflict', false)
            ->assertSet('pendingOfflineDraft', []);
    }

    public function test_decision_controls_require_a_live_connection(): void
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);
        app(SubmitSubmissionAction::class)->execute($submission);

        $this->actingAs($this->approver)
            ->get(route('submissions.show', $submission))
            ->assertOk()
            ->assertSee('data-requires-online', false)
            ->assertSee('data-test="approve-modal-trigger"', false)
            ->assertSee('data-test="approve"', false);
    }
}
