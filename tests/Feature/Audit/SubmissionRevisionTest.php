<?php

namespace Tests\Feature\Audit;

use App\Actions\Submissions\AttachSubmissionFilesAction;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\SubmissionFileRevision;
use App\Models\SubmissionRevision;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmissionRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    public function test_updating_a_request_writes_an_append_only_before_after_revision(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $this->actingAs($submitter);
        $submission = Submission::factory()->for($submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
            'title' => 'Old title',
        ]);

        $submission->update(['title' => 'New title']);

        $revision = SubmissionRevision::query()->where('submission_id', $submission->id)->latest('id')->first();
        $this->assertNotNull($revision);
        $this->assertSame($submitter->id, $revision->actor_id);
        $this->assertSame('Old title', $revision->before['title'] ?? null);
        $this->assertSame('New title', $revision->after['title'] ?? null);

        $this->expectException(\RuntimeException::class);
        $revision->update(['summary' => 'tamper']);
    }

    public function test_attaching_a_file_writes_file_provenance(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);

        app(AttachSubmissionFilesAction::class)->execute($submission, $submitter, [
            UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf'),
        ]);

        $revision = SubmissionFileRevision::query()->where('submission_id', $submission->id)->sole();
        $this->assertSame('added', $revision->action);
        $this->assertSame('quote.pdf', $revision->original_name);
        $this->assertNotNull($revision->sha256);
    }
}
