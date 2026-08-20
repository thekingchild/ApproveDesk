<?php

namespace Tests\Feature\Collaboration;

use App\Models\Submission;
use App\Models\SubmissionWatcher;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Notifications\CommentPostedNotification;
use App\Notifications\WatcherStatusNotification;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class WatchersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_added_watcher_gains_view_and_can_unfollow(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        $watcher = User::factory()->create()->assignRole('viewer');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        $this->actingAs($watcher)->get(route('submissions.show', $submission))->assertForbidden();

        Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $submission])
            ->set('watcherQuery', substr($watcher->name, 0, 4))
            ->call('addWatcher', $watcher->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('submission_watchers', [
            'submission_id' => $submission->id,
            'user_id' => $watcher->id,
        ]);

        $this->actingAs($watcher)->get(route('submissions.show', $submission))->assertOk();

        Livewire::actingAs($watcher)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->call('unfollow')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('submission_watchers', [
            'submission_id' => $submission->id,
            'user_id' => $watcher->id,
        ]);
        $this->actingAs($watcher)->get(route('submissions.show', $submission))->assertForbidden();
    }

    public function test_stranger_cannot_follow_or_add_watchers(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $stranger = User::factory()->create()->assignRole('viewer');
        $submission = Submission::factory()->for($submitter, 'submitter')->create();

        Livewire::actingAs($stranger)
            ->test('pages::submissions.show', ['submission' => $submission])
            ->assertForbidden();

        $this->assertSame(0, SubmissionWatcher::query()->count());
    }

    public function test_watchers_receive_comment_and_status_notifications_when_enabled(): void
    {
        Notification::fake();
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        $watcher = User::factory()->create()->assignRole('viewer');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        SubmissionWatcher::factory()->create([
            'submission_id' => $submission->id,
            'user_id' => $watcher->id,
            'notify_comments' => true,
            'notify_status' => true,
        ]);

        Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $submission])
            ->set('comment', 'Finance should stay in the loop.')
            ->call('postComment')
            ->assertHasNoErrors();

        Notification::assertSentTo($watcher, CommentPostedNotification::class);

        Livewire::actingAs($approver)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->call('act', 'approve')
            ->assertHasNoErrors();

        Notification::assertSentTo($watcher, WatcherStatusNotification::class);
    }

    public function test_muted_watchers_do_not_receive_comment_mail(): void
    {
        Notification::fake();
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        $watcher = User::factory()->create()->assignRole('viewer');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);
        SubmissionWatcher::factory()->create([
            'submission_id' => $submission->id,
            'user_id' => $watcher->id,
            'notify_comments' => false,
            'notify_status' => false,
        ]);

        Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $submission])
            ->set('comment', 'Muted followers should stay quiet.')
            ->call('postComment')
            ->assertHasNoErrors();

        Notification::assertNotSentTo($watcher, CommentPostedNotification::class);
    }
}
