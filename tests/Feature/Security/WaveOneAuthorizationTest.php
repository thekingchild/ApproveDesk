<?php

namespace Tests\Feature\Security;

use App\Actions\Audit\RequestDispositionAction;
use App\Actions\Audit\ReviewDispositionAction;
use App\Actions\Collaboration\FollowSubmissionAction;
use App\Actions\Collaboration\PostCommentAction;
use App\Enums\DispositionStatus;
use App\Exceptions\CollaborationException;
use App\Models\Department;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Support\SeparationOfDuties;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RV-SEC-09 (mentions), RV-SEC-12 (API department), RV-SEC-19 (disposition review).
 */
class WaveOneAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_follower_cannot_open_a_request_to_an_outsider_by_mentioning_them(): void
    {
        $requester = User::factory()->create()->assignRole('submitter');
        $follower = User::factory()->create()->assignRole('submitter');
        $outsider = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->create(['submitter_id' => $requester->id]);
        app(FollowSubmissionAction::class)->execute($submission, $requester, $follower);

        $this->assertFalse($follower->can('addWatcher', $submission->fresh()));

        try {
            app(PostCommentAction::class)->execute($submission->fresh(), $follower, 'Have a look', mentionedUserIds: [$outsider->id]);
            $this->fail('A mention opened the request to an outsider.');
        } catch (CollaborationException $exception) {
            $this->assertStringContainsString('cannot mention', $exception->getMessage());
        }

        $this->assertFalse($submission->fresh()->isWatchedBy($outsider));
        $this->assertFalse($outsider->can('view', $submission->fresh()));
    }

    public function test_requester_can_still_bring_someone_in_by_mentioning_them(): void
    {
        $requester = User::factory()->create()->assignRole('submitter');
        $colleague = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->create(['submitter_id' => $requester->id]);

        app(PostCommentAction::class)->execute($submission, $requester, 'Please weigh in', mentionedUserIds: [$colleague->id]);

        $this->assertTrue($colleague->can('view', $submission->fresh()));
    }

    public function test_api_submitter_cannot_route_through_another_department(): void
    {
        $own = Department::factory()->create();
        $other = Department::factory()->create();
        $submitter = User::factory()->create(['department_id' => $own->id])->assignRole('submitter');
        Sanctum::actingAs($submitter, ['submissions:write']);

        $this->postJson('/api/v1/submissions', ['type' => 'memo', 'title' => 'Steered', 'department_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');

        $this->postJson('/api/v1/submissions', ['type' => 'memo', 'title' => 'Own department', 'department_id' => $own->id])
            ->assertCreated();
    }

    public function test_disposition_is_decided_once_and_not_by_its_requester(): void
    {
        $requester = User::factory()->create()->assignRole('admin');
        $reviewer = User::factory()->create()->assignRole('admin');
        $review = app(RequestDispositionAction::class)->execute($requester, Submission::factory()->create(), 'Past retention');

        try {
            app(ReviewDispositionAction::class)->execute($requester, $review, DispositionStatus::Approved);
            $this->fail('The requester approved their own disposition.');
        } catch (InvalidArgumentException) {
            // expected
        }

        app(ReviewDispositionAction::class)->execute($reviewer, $review, DispositionStatus::Rejected, 'Keep it');

        $this->expectException(InvalidArgumentException::class);
        app(ReviewDispositionAction::class)->execute($reviewer, $review, DispositionStatus::Approved);
    }

    public function test_single_administrator_can_review_their_own_disposition_when_separation_of_duties_is_off(): void
    {
        Setting::put(SeparationOfDuties::SETTING, '0');
        $admin = User::factory()->create()->assignRole('admin');
        $review = app(RequestDispositionAction::class)->execute($admin, Submission::factory()->create(), 'Past retention');

        $decided = app(ReviewDispositionAction::class)->execute($admin, $review, DispositionStatus::Approved);

        $this->assertSame(DispositionStatus::Approved, $decided->status);
    }
}
