<?php

namespace Tests\Feature\Audit;

use App\Actions\Audit\GenerateEvidencePackAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Models\Comment;
use App\Models\Delegation;
use App\Models\NotificationDelivery;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Audit\EvidencePackVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class EvidencePackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $submitter;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
    }

    public function test_pack_contains_request_workflow_documents_comments_delegations_and_notifications(): void
    {
        $submission = $this->submittedRequest();
        Storage::disk('local')->put('submissions/'.$submission->id.'/note.txt', 'invoice body');
        SubmissionFile::factory()->create([
            'submission_id' => $submission->id,
            'original_name' => 'note.txt',
            'stored_path' => 'submissions/'.$submission->id.'/note.txt',
            'disk' => 'local',
            'mime_type' => 'text/plain',
            'size_bytes' => 12,
            'uploaded_by' => $this->submitter->id,
        ]);
        Comment::factory()->create([
            'commentable_id' => $submission->id,
            'commentable_type' => Submission::class,
            'author_id' => $this->submitter->id,
            'body' => 'Please review the invoice.',
        ]);
        $instance = $submission->workflowInstance()->firstOrFail();
        Delegation::factory()->create([
            'instance_id' => $instance->id,
            'step_id' => $instance->current_step_id,
            'delegator_id' => $this->approver->id,
            'delegate_id' => User::factory()->create()->id,
        ]);
        NotificationDelivery::query()->create([
            'user_id' => $this->approver->id,
            'kind' => 'action_required',
            'channel' => 'mail',
            'status' => 'sent',
            'attempts' => 1,
            'payload' => ['submission_id' => $submission->id, 'title' => $submission->title],
        ]);

        $pack = app(GenerateEvidencePackAction::class)->execute($submission, $this->admin);

        $this->assertNotNull($pack->signature);
        $this->assertNotSame('', $pack->manifest_hash);
        $this->assertTrue(Storage::disk('local')->exists($pack->stored_path));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($pack->stored_path)));
        foreach (['manifest.json', 'request.json', 'workflow.json', 'documents.json', 'comments.json', 'delegations.json', 'notifications.json', 'timestamps.json'] as $name) {
            $this->assertNotFalse($zip->locateName($name), $name.' missing from pack');
        }
        $request = json_decode((string) $zip->getFromName('request.json'), true);
        $this->assertSame($submission->reference_number, $request['reference_number']);
        $this->assertSame($submission->title, $request['title']);
        $documents = json_decode((string) $zip->getFromName('documents.json'), true);
        $this->assertSame(hash('sha256', 'invoice body'), $documents[0]['sha256']);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertArrayHasKey('signature', $manifest);
        $this->assertArrayHasKey('files', $manifest);
        $zip->close();
    }

    public function test_verify_detects_a_tampered_file_and_a_bad_signature(): void
    {
        $submission = $this->submittedRequest();
        $pack = app(GenerateEvidencePackAction::class)->execute($submission, $this->admin);
        $path = Storage::disk('local')->path($pack->stored_path);

        $this->assertTrue(app(EvidencePackVerifier::class)->verifyPath($path)->ok);

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('request.json', '{"tampered":true}');
        $zip->close();

        $tampered = app(EvidencePackVerifier::class)->verifyPath($path);
        $this->assertFalse($tampered->ok);
        $this->assertStringContainsString('hash', strtolower($tampered->error));

        $this->artisan('evidence:verify', ['path' => $path])->assertFailed();
    }

    public function test_administrator_can_download_an_evidence_pack(): void
    {
        $submission = $this->submittedRequest();

        $this->actingAs($this->admin)
            ->get(route('submissions.evidence.download', $submission))
            ->assertOk()
            ->assertHeader('content-type', 'application/zip');

        $this->actingAs($this->submitter)
            ->get(route('submissions.evidence.download', $submission))
            ->assertForbidden();
    }

    private function submittedRequest(): Submission
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
            'title' => 'Q3 invoice pack',
        ]);
        app(SubmitSubmissionAction::class)->execute($submission);

        return $submission->fresh();
    }
}
