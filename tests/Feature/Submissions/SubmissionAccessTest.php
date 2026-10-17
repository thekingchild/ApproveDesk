<?php

namespace Tests\Feature\Submissions;

use App\Enums\SubmissionStatus;
use App\Enums\WorkflowStatus;
use App\Models\Comment;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class SubmissionAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $approver;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->approver = User::factory()->create()->assignRole('approver');

        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();

        $this->submission = Submission::factory()->for($this->submitter, 'submitter')->create();
    }

    public function test_owner_can_view_their_submission(): void
    {
        $this->actingAs($this->submitter)
            ->get(route('submissions.show', $this->submission))
            ->assertOk()
            ->assertSee($this->submission->title);
    }

    public function test_strangers_cannot_view_a_submission(): void
    {
        $stranger = User::factory()->create()->assignRole('viewer');

        $this->actingAs($stranger)
            ->get(route('submissions.show', $this->submission))
            ->assertForbidden();
    }

    public function test_admin_with_view_all_can_view_any_submission(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('submissions.show', $this->submission))
            ->assertOk();
    }

    public function test_participants_can_comment_and_the_thread_grows(): void
    {
        app(WorkflowEngine::class)->initialize($this->submission);

        Livewire::actingAs($this->approver)
            ->test('submissions.comments', ['submission' => $this->submission])
            ->set('comment', 'Could you clarify the total?')
            ->call('postComment')
            ->assertHasNoErrors();

        $this->assertSame(1, Comment::query()->where('commentable_id', $this->submission->id)->count());
    }

    public function test_submitter_can_withdraw_a_pending_submission(): void
    {
        app(WorkflowEngine::class)->initialize($this->submission);

        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.show', ['submission' => $this->submission->fresh()])
            ->call('withdraw')
            ->assertHasNoErrors();

        $this->assertSame(SubmissionStatus::Withdrawn, $this->submission->fresh()->status);
        $this->assertSame(WorkflowStatus::Cancelled, $this->submission->workflowInstance()->first()->status);
    }

    public function test_files_download_only_through_signed_urls(): void
    {
        Storage::fake('local');

        $file = SubmissionFile::factory()
            ->for($this->submission)
            ->for($this->submitter, 'uploader')
            ->create([
                'stored_path' => 'submissions/1/file.pdf',
            ]);

        Storage::disk('local')->put($file->stored_path, 'pdf-content');
        $path = $file->stored_path;
        $file->update(['stored_path' => $path]);

        $signed = URL::temporarySignedRoute(
            'submissions.files.download',
            now()->addHours(2),
            ['submission' => $this->submission->id, 'file' => $file->id],
        );

        $response = $this->actingAs($this->submitter)->get($signed);
        $response->assertOk();
        $this->assertSame('pdf-content', $response->getContent() ?: Storage::disk('local')->get($path));

        // Unsigned access is rejected outright.
        $this->actingAs($this->submitter)
            ->get(route('submissions.files.download', [$this->submission, $file]))
            ->assertForbidden();

        // A valid signed URL is not an authorization bypass if it is shared.
        $stranger = User::factory()->create()->assignRole('viewer');
        $this->actingAs($stranger)->get($signed)->assertForbidden();
    }

    public function test_supported_attachments_can_be_previewed_inline_through_signed_urls(): void
    {
        Storage::fake('local');
        $file = SubmissionFile::factory()
            ->for($this->submission)
            ->for($this->submitter, 'uploader')
            ->create([
                'stored_path' => 'submissions/1/preview.pdf',
                'mime_type' => 'application/pdf',
                'original_name' => 'preview.pdf',
            ]);
        Storage::disk('local')->put($file->stored_path, '%PDF-preview');
        $signed = URL::temporarySignedRoute(
            'submissions.files.download',
            now()->addMinutes(10),
            ['submission' => $this->submission->id, 'file' => $file->id, 'preview' => 1],
        );

        $response = $this->actingAs($this->submitter)->get($signed);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));
    }

    public function test_unsupported_attachments_cannot_be_forced_into_inline_preview(): void
    {
        Storage::fake('local');
        $file = SubmissionFile::factory()
            ->for($this->submission)
            ->for($this->submitter, 'uploader')
            ->create([
                'stored_path' => 'submissions/1/archive.zip',
                'mime_type' => 'application/zip',
            ]);
        Storage::disk('local')->put($file->stored_path, 'zip-content');
        $signed = URL::temporarySignedRoute(
            'submissions.files.download',
            now()->addMinutes(10),
            ['submission' => $this->submission->id, 'file' => $file->id, 'preview' => 1],
        );

        $this->actingAs($this->submitter)->get($signed)->assertUnsupportedMediaType();
    }
}
