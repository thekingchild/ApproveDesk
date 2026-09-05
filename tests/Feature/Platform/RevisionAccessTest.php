<?php

namespace Tests\Feature\Platform;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevisionAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_submitter_can_edit_returned_and_awaiting_information_requests_but_other_users_cannot(): void
    {
        $submitter = User::factory()->create();
        $other = User::factory()->create();

        foreach ([SubmissionStatus::Returned, SubmissionStatus::AwaitingInformation] as $status) {
            $submission = Submission::factory()->for($submitter, 'submitter')->create(['status' => $status]);
            $this->assertTrue($submitter->can('update', $submission));
            $this->assertFalse($other->can('update', $submission));
            $this->actingAs($submitter)->get(route('submissions.edit', $submission))->assertOk();
            $this->actingAs($other)->get(route('submissions.edit', $submission))->assertForbidden();
        }
    }
}
