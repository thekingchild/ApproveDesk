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

    public function test_body_history_is_a_collapsed_formatted_before_after_comparison(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $this->actingAs($submitter);
        $submission = Submission::factory()->for($submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
            'body' => '<p>Original <strong>request</strong></p>',
        ]);
        $submission->update(['body' => '<p>Updated <em>request</em></p>']);

        $response = $this->get(route('submissions.show', $submission))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $history = $xpath->query('//*[@data-test="request-history"]')->item(0);
        $this->assertNotNull($history);
        $comparisons = $xpath->query('.//details[not(@open)]', $history);
        $this->assertCount(1, $comparisons);
        $comparison = $comparisons->item(0);
        $this->assertSame('View body changes', trim($xpath->evaluate('string(.//summary)', $comparison)));
        $this->assertSame(1.0, $xpath->evaluate('count(.//*[@data-test="body-before"])', $history));
        $this->assertSame(1.0, $xpath->evaluate('count(.//*[@data-test="body-after"])', $history));
        $this->assertSame(1.0, $xpath->evaluate('count(.//details[not(@open)]//*[@data-test="body-before"])', $history));
        $this->assertSame(1.0, $xpath->evaluate('count(.//details[not(@open)]//*[@data-test="body-after"])', $history));
        $this->assertSame(0.0, $xpath->evaluate('count(.//*[@data-test="body-before" or @data-test="body-after"][not(ancestor::details)])', $history));
        $this->assertSame('Original request', trim($xpath->evaluate('string(.//*[@data-test="body-before"])', $comparison)));
        $this->assertSame('Updated request', trim($xpath->evaluate('string(.//*[@data-test="body-after"])', $comparison)));
        $this->assertSame(1.0, $xpath->evaluate('count(.//*[@data-test="body-before"]//strong)', $comparison));
        $this->assertSame(1.0, $xpath->evaluate('count(.//*[@data-test="body-after"]//em)', $comparison));
        $this->assertStringNotContainsString('<p>', $history->textContent);
        $this->assertStringNotContainsString('body:', $history->textContent);
        $this->assertStringContainsString('Before', $comparison->textContent);
        $this->assertStringContainsString('After', $comparison->textContent);
        $this->assertDatabaseCount('submission_revisions', 1);
        $this->assertSame('<p>Original <strong>request</strong></p>', $submission->revisions()->sole()->before['body']);
    }

    public function test_historical_body_html_is_sanitized_without_rewriting_audit_data(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $this->actingAs($submitter);
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        $unsafe = '<p onclick="historyAttack()">Historical text</p><script>historyAttack()</script><a href="javascript:historyAttack()">Link</a>';
        $revision = SubmissionRevision::query()->create([
            'submission_id' => $submission->id,
            'actor_id' => $submitter->id,
            'summary' => 'body, title',
            'before' => ['body' => $unsafe, 'title' => '<b>Old title</b>'],
            'after' => ['body' => $unsafe, 'title' => 'New title'],
        ]);

        $response = $this->get(route('submissions.show', $submission))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $history = $xpath->query('//*[@data-test="request-history"]')->item(0);
        $this->assertNotNull($history);
        $html = $document->saveHTML($history);
        $this->assertStringNotContainsString('historyAttack', $html);
        $this->assertSame(2.0, $xpath->evaluate('count(.//details//p)', $history));
        $this->assertStringContainsString('title: <b>Old title</b> → New title', $history->textContent);
        $this->assertSame($unsafe, $revision->fresh()->before['body']);
        $this->assertSame($unsafe, $revision->fresh()->after['body']);
    }

    public function test_empty_body_revisions_show_an_explicit_placeholder(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $this->actingAs($submitter);
        $submission = Submission::factory()->for($submitter, 'submitter')->create(['body' => null]);
        $submission->update(['body' => '<p>New body</p>']);
        $submission->update(['body' => null]);

        $response = $this->get(route('submissions.show', $submission))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $history = $xpath->query('//*[@data-test="request-history"]')->item(0);
        $this->assertNotNull($history);
        $this->assertSame(2.0, $xpath->evaluate('count(.//details[not(@open)])', $history));
        $this->assertSame('No body', trim($xpath->evaluate('string((.//*[@data-test="body-after"])[1])', $history)));
        $this->assertSame('No body', trim($xpath->evaluate('string((.//*[@data-test="body-before"])[2])', $history)));
        $this->assertDatabaseCount('submission_revisions', 2);
    }

    public function test_a_zero_body_is_not_mistaken_for_an_empty_revision(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $this->actingAs($submitter);
        $submission = Submission::factory()->for($submitter, 'submitter')->create(['body' => '0']);
        $submission->update(['body' => '1']);
        $submission->update(['body' => '0']);

        $response = $this->get(route('submissions.show', $submission))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $history = $xpath->query('//*[@data-test="request-history"]')->item(0);
        $this->assertNotNull($history);
        $this->assertSame('0', trim($xpath->evaluate('string((.//*[@data-test="body-after"])[1])', $history)));
        $this->assertSame('0', trim($xpath->evaluate('string((.//*[@data-test="body-before"])[2])', $history)));
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
