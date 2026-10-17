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

/** ADR 0006 split of the request page into decision, comments, followers and attachments (RV-ARC-02 part 2). */
class RequestPageSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $approver;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->requester = User::factory()->create()->assignRole('submitter');
        $this->approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        $this->submission = Submission::factory()->create(['submitter_id' => $this->requester->id, 'status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($this->submission);
    }

    public function test_the_approver_sees_every_section(): void
    {
        $this->actingAs($this->approver)->get(route('submissions.show', $this->submission))
            ->assertOk()
            ->assertSee('Your decision')
            ->assertSee('data-test="approve-modal-trigger"', false)
            ->assertSee('data-test="watchers-card"', false)
            ->assertSee('Comments')
            ->assertSee('Workflow progress');
    }

    public function test_the_requester_sees_no_decision_panel(): void
    {
        $this->actingAs($this->requester)->get(route('submissions.show', $this->submission))
            ->assertOk()
            ->assertDontSee('Your decision')
            ->assertSee('data-test="watchers-card"', false);
    }

    public function test_sections_refuse_someone_who_cannot_see_the_request(): void
    {
        $stranger = User::factory()->create()->assignRole('submitter');

        foreach (['submissions.decision', 'submissions.comments', 'submissions.followers', 'submissions.attachments'] as $component) {
            Livewire::actingAs($stranger)->test($component, ['submission' => $this->submission])->assertForbidden();
        }
    }

    public function test_commenting_refreshes_the_followers_card(): void
    {
        Livewire::actingAs($this->approver)->test('submissions.comments', ['submission' => $this->submission])
            ->set('comment', 'Looks fine to me.')
            ->call('postComment')
            ->assertHasNoErrors()
            ->assertDispatched('submission-followers-changed');
    }
}
