<?php

namespace Tests\Feature\Submissions;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Notifications\ActionRequiredNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CreateSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $firstApprover;

    private User $secondApprover;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->firstApprover = User::factory()->create()->assignRole('approver');
        $this->secondApprover = User::factory()->create()->assignRole('approver');

        WorkflowTemplate::factory()
            ->default()
            ->sequential([$this->firstApprover, $this->secondApprover])
            ->create();
    }

    public function test_wizard_creates_submission_and_starts_workflow(): void
    {
        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create')
            ->set('type', 'memo')
            ->set('title', 'Quarterly budget note')
            ->set('body', 'Please review the Q3 numbers.')
            ->set('priority', 'high')
            ->call('next')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('submissions.show', Submission::first()));

        $submission = Submission::first();

        $this->assertSame(SubmissionStatus::InReview, $submission->status);
        $this->assertNotNull($submission->workflowInstance);
        $this->assertSame(1, $submission->workflowInstance->currentStep->step_order);

        Notification::assertSentTo(
            [$this->firstApprover],
            ActionRequiredNotification::class,
            fn ($notification, array $channels) => in_array('mail', $channels) && in_array('database', $channels),
        );
        Notification::assertNotSentTo([$this->secondApprover], ActionRequiredNotification::class);
    }

    public function test_autosave_persists_a_draft_without_starting_the_workflow(): void
    {
        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create')
            ->set('title', 'Half-finished thought')
            ->call('autosave')
            ->assertHasNoErrors();

        $draft = Submission::first();

        $this->assertNotNull($draft);
        $this->assertSame(SubmissionStatus::Draft, $draft->status);
        $this->assertNull($draft->workflowInstance);

        Notification::assertNothingSent();
    }

    public function test_attachments_are_stored_on_the_private_disk(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create')
            ->set('title', 'With attachments')
            ->call('next')
            ->set('attachments', [UploadedFile::fake()->create('report.pdf', 500, 'application/pdf')])
            ->call('submit')
            ->assertHasNoErrors();

        $submission = Submission::first();

        $this->assertCount(1, $submission->files);
        $this->assertSame('report.pdf', $submission->files->first()->original_name);
        Storage::disk('local')->assertExists($submission->files->first()->stored_path);
    }

    public function test_wizard_is_gated_by_permission(): void
    {
        $approver = User::factory()->create()->assignRole('viewer');

        $this->actingAs($approver)
            ->get(route('submissions.create'))
            ->assertForbidden();
    }

    public function test_submitter_sees_their_submissions_list(): void
    {
        Submission::factory()->count(2)->for($this->submitter, 'submitter')->create();

        $this->actingAs($this->submitter)
            ->get(route('submissions.index'))
            ->assertOk()
            ->assertSee('SUB-');
    }
}
