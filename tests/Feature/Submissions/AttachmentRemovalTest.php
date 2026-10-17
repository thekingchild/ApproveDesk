<?php

namespace Tests\Feature\Submissions;

use App\DTOs\WorkflowActionData;
use App\Enums\SubmissionFileRevisionAction;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Models\LegalHold;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionFileRevision;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Audit\EvidencePackBuilder;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/** RV-DATA-06: attachments approvers relied on are kept when the requester removes them. */
class AttachmentRemovalTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        $this->submitter = User::factory()->create()->assignRole('submitter');
    }

    public function test_removing_a_file_from_a_returned_request_keeps_it_as_evidence(): void
    {
        [$submission, $file] = $this->returnedRequestWithFile();

        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create', ['submission' => $submission])
            ->call('removeSavedFile', $file->id)
            ->assertHasNoErrors();

        $this->assertSame([], $submission->files()->pluck('id')->all());
        $kept = SubmissionFile::withTrashed()->findOrFail($file->id);
        $this->assertTrue($kept->trashed());
        $this->assertSame($this->submitter->id, $kept->removed_by);
        Storage::disk('local')->assertExists($file->stored_path);
        $this->assertSame([SubmissionFileRevisionAction::Added, SubmissionFileRevisionAction::Removed], SubmissionFileRevision::query()->orderBy('id')->pluck('action')->all());

        Livewire::actingAs($this->approver)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->assertSee('Removed by the requester')
            ->assertSee('quote.pdf');

        $url = URL::temporarySignedRoute('submissions.files.download', now()->addMinutes(5), ['submission' => $submission->id, 'file' => $file->id]);
        $this->actingAs($this->approver)->get($url)->assertOk();

        $documents = json_decode(app(EvidencePackBuilder::class)->files($submission->fresh())['documents.json'], true);
        $this->assertSame('quote.pdf', $documents[0]['name']);
        $this->assertSame($this->submitter->name, $documents[0]['removed_by']);
        $this->assertNotNull($documents[0]['sha256']);
    }

    public function test_removing_a_file_from_an_unsubmitted_draft_deletes_it(): void
    {
        Livewire::actingAs($this->submitter)->test('pages::submissions.create')
            ->set('title', 'Quote')
            ->set('attachments', [UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')])
            ->call('saveDraft');
        $draft = Submission::query()->where('submitter_id', $this->submitter->id)->sole();
        $file = $draft->files()->sole();

        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create', ['submission' => $draft])
            ->call('removeSavedFile', $file->id);

        $this->assertNull(SubmissionFile::withTrashed()->find($file->id));
        Storage::disk('local')->assertMissing($file->stored_path);
        $this->assertSame([SubmissionFileRevisionAction::Added, SubmissionFileRevisionAction::Removed], SubmissionFileRevision::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_files_on_legal_hold_cannot_be_removed(): void
    {
        [$submission, $file] = $this->returnedRequestWithFile();
        LegalHold::query()->create(['submission_id' => $submission->id, 'placed_by' => $this->approver->id, 'reason' => 'Dispute', 'placed_at' => now()]);

        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.create', ['submission' => $submission])
            ->call('removeSavedFile', $file->id)
            ->assertHasErrors('attachments')
            ->assertSee('legal hold');

        $this->assertFalse($file->fresh()->trashed());
        $this->assertSame([SubmissionFileRevisionAction::Added], SubmissionFileRevision::query()->pluck('action')->all());
    }

    /** @return array{Submission, SubmissionFile} */
    private function returnedRequestWithFile(): array
    {
        Livewire::actingAs($this->submitter)->test('pages::submissions.create')
            ->set('title', 'Quote')
            ->set('attachments', [UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')])
            ->call('submit');
        $submission = Submission::query()->where('submitter_id', $this->submitter->id)->sole();
        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->first(), new WorkflowActionData($this->approver, WorkflowActionType::Return, 'Fix the title'));
        $this->assertSame(SubmissionStatus::Returned, $submission->fresh()->status);

        return [$submission->fresh(), $submission->files()->sole()];
    }
}
