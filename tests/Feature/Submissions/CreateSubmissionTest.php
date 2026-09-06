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

    public function test_general_request_form_does_not_autosave(): void
    {
        $page = Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create')
            ->assertSee('Save draft')
            ->assertDontSee('Changes autosave every 30 seconds.')
            ->assertDontSee('Drafts auto-save every 30 seconds while you type.')
            ->assertDontSee('Autosave failed.')
            ->assertDontSee('wire:poll.30s="autosave"', false)
            ->assertDontSee('data-test="autosave-status"', false);

        $this->assertFalse(
            method_exists($page->instance(), 'autosave'),
            'General Request must not expose an autosave action.',
        );

        $this->assertSame(0, Submission::query()->count());
    }

    public function test_save_draft_persists_without_starting_the_workflow(): void
    {
        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create')
            ->set('title', 'Half-finished thought')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertRedirect(route('submissions.index'));

        $draft = Submission::first();

        $this->assertNotNull($draft);
        $this->assertSame(SubmissionStatus::Draft, $draft->status);
        $this->assertNull($draft->workflowInstance);

        Notification::assertNothingSent();
    }

    public function test_save_draft_rejects_an_invalid_title(): void
    {
        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create')
            ->set('title', str_repeat('x', 501))
            ->call('saveDraft')
            ->assertHasErrors(['title']);

        $this->assertSame(0, Submission::query()->count());
    }

    public function test_owner_can_reopen_edit_and_submit_a_saved_draft(): void
    {
        $draft = Submission::factory()->for($this->submitter, 'submitter')->draft()->create([
            'type' => 'note',
            'title' => 'Original draft title',
            'body' => 'Original body',
            'priority' => 'normal',
        ]);

        $this->actingAs($this->submitter)
            ->get(route('submissions.edit', $draft))
            ->assertOk()
            ->assertSee('Edit draft');

        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create', ['submission' => $draft])
            ->assertSet('draftId', $draft->id)
            ->assertSet('title', 'Original draft title')
            ->set('title', 'Ready for approval')
            ->set('body', 'Completed body')
            ->call('next')
            ->call('next')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('submissions.show', $draft));

        $draft->refresh();

        $this->assertSame('Ready for approval', $draft->title);
        $this->assertSame('Completed body', $draft->body);
        $this->assertSame(SubmissionStatus::InReview, $draft->status);
        $this->assertNotNull($draft->workflowInstance);
    }

    public function test_non_owner_cannot_open_the_draft_editor(): void
    {
        $draft = Submission::factory()->for($this->submitter, 'submitter')->draft()->create();
        $otherSubmitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($otherSubmitter)
            ->get(route('submissions.edit', $draft))
            ->assertForbidden();
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

    public function test_executable_attachments_are_rejected_by_mime_validation(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create')
            ->set('title', 'Unsafe attachment attempt')
            ->call('next')
            ->set('attachments', [UploadedFile::fake()->create('payload.php', 10, 'application/x-php')])
            ->call('submit')
            ->assertHasErrors(['attachments.0']);

        $this->assertSame(0, Submission::query()->count());
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
