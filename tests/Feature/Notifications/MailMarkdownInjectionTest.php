<?php

namespace Tests\Feature\Notifications;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\SubmissionDecidedNotification;
use App\Support\MarkdownText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RV-SEC-14: user text in notification email stays literal.
 */
class MailMarkdownInjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_markdown_link_in_a_title_is_not_rendered_as_a_link(): void
    {
        $requester = User::factory()->create();
        $submission = Submission::factory()->create([
            'submitter_id' => $requester->id,
            'title' => '[Session expired - sign in](https://phish.example/login)',
        ]);

        $html = (string) (new SubmissionDecidedNotification($submission, SubmissionStatus::Approved))
            ->toMail($requester)
            ->render();

        $this->assertStringNotContainsString('href="https://phish.example', $html);
        $this->assertStringContainsString('Session expired - sign in', $html);
        $this->assertStringContainsString(route('submissions.show', $submission), $html, 'The genuine action link still works.');
    }

    public function test_escape_neutralises_markdown_control_characters(): void
    {
        $this->assertSame('\\[x\\]\\(y\\) \\*z\\*', MarkdownText::escape('[x](y) *z*'));
    }
}
