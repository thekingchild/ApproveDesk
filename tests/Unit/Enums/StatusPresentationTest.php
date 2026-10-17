<?php

namespace Tests\Unit\Enums;

use App\Enums\SubmissionPriority;
use App\Enums\SubmissionStatus;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** RV-ARC-15: one label and colour per status, used by every list and page. */
class StatusPresentationTest extends TestCase
{
    public function test_rv_arc_15_every_status_has_the_help_centre_label_and_a_colour(): void
    {
        $help = (string) file_get_contents(base_path('docs/kb/statuses.md'));

        foreach (SubmissionStatus::cases() as $status) {
            $this->assertStringContainsString('| '.$status->label().' |', $help, "{$status->value} is named as in Help.");
            $this->assertContains($status->color(), ['zinc', 'blue', 'lime', 'red', 'amber']);
        }

        $this->assertSame('amber', SubmissionStatus::AwaitingInformation->color(), 'The dashboard copy used to fall back to grey.');
        $this->assertTrue(SubmissionStatus::Returned->needsRequester());
        $this->assertFalse(SubmissionStatus::InReview->needsRequester());
    }

    public function test_rv_arc_15_priorities_sort_most_urgent_first(): void
    {
        $sorted = collect(SubmissionPriority::cases())->sortBy(fn (SubmissionPriority $priority): int => $priority->rank())->values()->all();

        $this->assertSame([SubmissionPriority::Urgent, SubmissionPriority::High, SubmissionPriority::Normal, SubmissionPriority::Low], $sorted);
    }

    public function test_rv_arc_15_the_badge_component_renders_label_and_colour(): void
    {
        $html = Blade::render('<x-submission-status-badge :status="$status" />', ['status' => SubmissionStatus::AwaitingInformation]);

        $this->assertStringContainsString('Awaiting information', $html);
        $this->assertStringContainsString('amber', $html);
    }
}
