<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_number_is_generated_automatically(): void
    {
        $submission = Submission::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^SUB-\d{4}-\d{5}$/',
            $submission->reference_number,
        );
    }

    public function test_reference_numbers_are_unique_across_submissions(): void
    {
        $first = Submission::factory()->create();
        $second = Submission::factory()->create();

        $this->assertNotSame($first->reference_number, $second->reference_number);
    }

    public function test_explicit_reference_number_is_preserved(): void
    {
        $submission = Submission::factory()->create([
            'reference_number' => 'SUB-2026-99999',
        ]);

        $this->assertSame('SUB-2026-99999', $submission->reference_number);
    }

    public function test_status_is_cast_to_enum(): void
    {
        $submission = Submission::factory()->create(['status' => 'in_review']);

        $this->assertSame(SubmissionStatus::InReview, $submission->status);
    }

    public function test_submitter_relation_resolves(): void
    {
        $user = User::factory()->create();
        $submission = Submission::factory()->for($user, 'submitter')->create();

        $this->assertTrue($submission->submitter->is($user));
    }

    public function test_submission_relates_files_and_workflow_instance(): void
    {
        $submission = Submission::factory()->has(SubmissionFile::factory()->count(2), 'files')->create();
        WorkflowInstance::factory()->for($submission)->create();

        $this->assertCount(2, $submission->files);
        $this->assertNotNull($submission->workflowInstance);
    }

    public function test_submission_body_is_sanitized_before_storage(): void
    {
        $submission = Submission::factory()->create([
            'body' => '<p onclick="bad()">Safe <strong>content</strong></p><script>alert(1)</script>',
        ]);

        $this->assertSame('<p>Safe <strong>content</strong></p>', $submission->body);
        $this->assertSame($submission->body, $submission->fresh()->body);
    }
}
