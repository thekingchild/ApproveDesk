<?php

namespace Tests\Feature\Collaboration;

use App\Models\Comment;
use App\Models\CommentRevision;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Notifications\MentionedInCommentNotification;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class CommentsMentionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mention_notifies_and_grants_view(): void
    {
        Notification::fake();
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        $mentioned = User::factory()->create(['name' => 'Casey Mentioned'])->assignRole('viewer');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        Livewire::actingAs($submitter)
            ->test('submissions.comments', ['submission' => $submission])
            ->set('mentionQuery', 'Casey')
            ->call('addMention', $mentioned->id)
            ->set('comment', 'Need eyes from @{'.$mentioned->id.'}')
            ->call('postComment')
            ->assertHasNoErrors();

        Notification::assertSentTo($mentioned, MentionedInCommentNotification::class);
        $this->actingAs($mentioned)->get(route('submissions.show', $submission))->assertOk();
        $this->assertDatabaseHas('comment_mentions', ['user_id' => $mentioned->id]);
    }

    public function test_thread_reply_attaches_to_the_parent(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        Livewire::actingAs($submitter)
            ->test('submissions.comments', ['submission' => $submission])
            ->set('comment', 'Starting thread.')
            ->call('postComment')
            ->assertHasNoErrors();

        $parent = Comment::query()->firstOrFail();

        Livewire::actingAs($approver)
            ->test('submissions.comments', ['submission' => $submission])
            ->call('startReply', $parent->id)
            ->set('replyBody', 'Here is the reply.')
            ->call('postReply')
            ->assertHasNoErrors();

        $reply = Comment::query()->where('parent_id', $parent->id)->first();
        $this->assertNotNull($reply);
        $this->assertSame('Here is the reply.', $reply->body);
    }

    public function test_edit_window_writes_append_only_history_then_locks(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($submitter, 'submitter')->create();

        Livewire::actingAs($submitter)
            ->test('submissions.comments', ['submission' => $submission])
            ->set('comment', 'Original wording.')
            ->call('postComment')
            ->assertHasNoErrors();

        $comment = Comment::query()->firstOrFail();

        Livewire::actingAs($submitter)
            ->test('submissions.comments', ['submission' => $submission])
            ->call('startEdit', $comment->id)
            ->set('editBody', 'Corrected wording.')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame('Corrected wording.', $comment->fresh()->body);
        $this->assertDatabaseHas('comment_revisions', [
            'comment_id' => $comment->id,
            'body' => 'Original wording.',
        ]);

        $revision = CommentRevision::query()->firstOrFail();
        $this->expectException(\RuntimeException::class);
        $revision->update(['body' => 'tamper']);
    }

    public function test_edit_is_rejected_after_the_window(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00');
        $submitter = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($submitter, 'submitter')->create();

        Livewire::actingAs($submitter)
            ->test('submissions.comments', ['submission' => $submission])
            ->set('comment', 'Will go stale.')
            ->call('postComment')
            ->assertHasNoErrors();

        $comment = Comment::query()->firstOrFail();
        Carbon::setTestNow('2026-08-20 10:16:00');

        Livewire::actingAs($submitter)
            ->test('submissions.comments', ['submission' => $submission])
            ->call('startEdit', $comment->id)
            ->assertForbidden();
    }

    public function test_nested_reply_to_a_reply_is_rejected(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        $parent = Comment::factory()->create([
            'commentable_id' => $submission->id,
            'commentable_type' => Submission::class,
            'author_id' => $submitter->id,
        ]);
        $reply = Comment::factory()->create([
            'commentable_id' => $submission->id,
            'commentable_type' => Submission::class,
            'author_id' => $submitter->id,
            'parent_id' => $parent->id,
        ]);

        Livewire::actingAs($submitter)
            ->test('submissions.comments', ['submission' => $submission])
            ->set('replyParentId', $reply->id)
            ->set('replyBody', 'Too deep.')
            ->call('postReply')
            ->assertHasErrors('replyBody');
    }
}
