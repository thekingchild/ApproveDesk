<?php

use App\Actions\Collaboration\EditCommentAction;
use App\Actions\Collaboration\PostCommentAction;
use App\Exceptions\CollaborationException;
use App\Models\Comment;
use App\Models\User;
use App\Services\Collaboration\MentionParser;
use App\Services\Workflow\AssigneeDirectory;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use App\Models\Submission;
use Livewire\Component;

/*
 * Comments card of the request page (ADR 0006 split, RV-ARC-02): threads,
 * replies, edits and mentions. Announces submission-followers-changed after
 * a post, since commenting can make the author a follower.
 */
new class extends Component {
    public Submission $submission;

    public string $comment = '';

    public string $replyBody = '';

    public ?int $replyParentId = null;

    #[Locked]
    public ?int $editingCommentId = null;

    public string $editBody = '';

    public string $mentionQuery = '';

    /** @var array<int, int> */
    public array $mentionedUserIds = [];

    public function mount(Submission $submission): void
    {
        $this->authorize('view', $submission);
        $this->submission = $submission;
    }

    public function hydrate(): void
    {
        $this->submission->loadMissing(['watchers']);
    }

    public function postComment(): void
    {
        $this->validate(['comment' => ['required', 'string', 'max:5000']]);
        $this->authorize('comment', $this->submission);

        try {
            app(PostCommentAction::class)->execute(
                $this->submission,
                Auth::user(),
                $this->comment,
                null,
                $this->mentionedUserIds,
            );
        } catch (CollaborationException $exception) {
            $this->addError('comment', $exception->userMessage());

            return;
        }

        $this->reset('comment', 'mentionedUserIds', 'mentionQuery');
        $this->refreshCollaboration();
    }

    public function postReply(): void
    {
        $this->validate([
            'replyBody' => ['required', 'string', 'max:5000'],
            'replyParentId' => ['required', 'integer'],
        ]);
        $this->authorize('comment', $this->submission);

        try {
            app(PostCommentAction::class)->execute(
                $this->submission,
                Auth::user(),
                $this->replyBody,
                $this->replyParentId,
            );
        } catch (CollaborationException $exception) {
            $this->addError('replyBody', $exception->userMessage());

            return;
        }

        $this->reset('replyBody', 'replyParentId');
        $this->refreshCollaboration();
    }

    public function startReply(int $commentId): void
    {
        $this->replyParentId = $commentId;
        $this->replyBody = '';
        $this->editingCommentId = null;
    }

    public function startEdit(int $commentId): void
    {
        $comment = $this->submission->comments()->findOrFail($commentId);
        $this->authorize('update', $comment);
        $this->editingCommentId = $comment->id;
        $this->editBody = $comment->body;
        $this->replyParentId = null;
    }

    public function saveEdit(): void
    {
        $this->validate(['editBody' => ['required', 'string', 'max:5000']]);
        // Only a comment on this request that the viewer may still edit (RV-LAR-15).
        $comment = $this->submission->comments()->findOrFail($this->editingCommentId);
        $this->authorize('update', $comment);

        try {
            app(EditCommentAction::class)->execute($comment, Auth::user(), $this->editBody);
        } catch (CollaborationException $exception) {
            $this->addError('editBody', $exception->userMessage());

            return;
        }

        $this->reset('editingCommentId', 'editBody');
        $this->refreshCollaboration();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingCommentId', 'editBody', 'replyParentId', 'replyBody');
    }

    public function addMention(int $userId): void
    {
        $this->authorize('comment', $this->submission);
        $parser = app(MentionParser::class);
        $this->comment = $parser->appendToken($this->comment, $userId);
        $this->mentionedUserIds = array_values(array_unique([...$this->mentionedUserIds, $userId]));
        $this->mentionQuery = '';
        unset($this->mentionResults);
    }

    public function removeMention(int $userId): void
    {
        $this->authorize('comment', $this->submission);
        $this->mentionedUserIds = array_values(array_filter($this->mentionedUserIds, fn (int $id): bool => $id !== $userId));
        $this->comment = trim((string) preg_replace('/@\{'.$userId.'\}/', '', $this->comment));
        unset($this->mentionResults);
    }

    #[Computed]
    public function commentThreads(): \Illuminate\Database\Eloquent\Collection
    {
        $threads = $this->submission->comments()
            ->with(['author', 'mentions.user', 'revisions.editor', 'replies.author', 'replies.mentions.user', 'replies.revisions.editor'])
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get();

        // Every comment here belongs to this request; hand it over instead of letting
        // the comment policy lazy-load it once per comment (RV-PERF-03).
        foreach ($threads as $comment) {
            $comment->setRelation('commentable', $this->submission);
            foreach ($comment->replies as $reply) {
                $reply->setRelation('commentable', $this->submission);
            }
        }

        return $threads;
    }

    #[Computed]
    public function mentionResults(): \Illuminate\Support\Collection
    {
        return app(AssigneeDirectory::class)->search('user', $this->mentionQuery, [Auth::id(), ...$this->mentionedUserIds]);
    }

    #[Computed]
    public function mentionedUsers(): \Illuminate\Support\Collection
    {
        // Only people who can be mentioned; a tampered id list must not reveal hidden accounts.
        return User::query()->assignable()->where('is_active', true)
            ->whereIn('id', array_map('intval', $this->mentionedUserIds))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    protected function displayComment(Comment $comment): string
    {
        return app(MentionParser::class)->display(
            $comment->body,
            $comment->mentions->map->user->filter(),
        );
    }

    private function refreshCollaboration(): void
    {
        unset($this->commentThreads, $this->mentionResults, $this->mentionedUsers);
        // Commenting can make the author a follower.
        $this->dispatch('submission-followers-changed');
    }
}; ?>

    <flux:card>
        <flux:heading size="sm">{{ __('Comments') }}</flux:heading>

        <ul class="mt-4 space-y-4">
            @forelse ($this->commentThreads as $c)
                <li class="text-sm" data-test="comment-{{ $c->id }}">
                    <span class="font-medium">{{ $c->author->name }}</span>
                    <span class="text-xs text-zinc-400"><x-time :at="$c->created_at" /></span>
                    @if ($c->edited_at)
                        <span class="text-xs text-zinc-400">{{ __('(edited)') }}</span>
                    @endif
                    @if ($this->editingCommentId === $c->id)
                        <form wire:submit="saveEdit" class="mt-2 space-y-2">
                            <flux:textarea wire:model="editBody" rows="2" />
                            <flux:error name="editBody" />
                            <div class="flex gap-2">
                                <flux:button type="submit" size="xs" variant="primary">{{ __('Save') }}</flux:button>
                                <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <p class="mt-1 rounded-md bg-zinc-50 p-2 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $this->displayComment($c) }}</p>
                    @endif
                    @if ($c->revisions->isNotEmpty())
                        <details class="mt-1 text-xs text-zinc-500">
                            <summary>{{ __('Edit history') }}</summary>
                            <ul class="mt-1 space-y-1">
                                @foreach ($c->revisions as $revision)
                                    <li><x-time :at="$revision->created_at" />: {{ $revision->body }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                    <div class="mt-1 flex gap-2">
                        @can('comment', $submission)
                            <flux:button size="xs" variant="ghost" wire:click="startReply({{ $c->id }})" data-test="reply-{{ $c->id }}">{{ __('Reply') }}</flux:button>
                        @endcan
                        @can('update', $c)
                            <flux:button size="xs" variant="ghost" wire:click="startEdit({{ $c->id }})" data-test="edit-comment-{{ $c->id }}">{{ __('Edit') }}</flux:button>
                        @endcan
                    </div>
                    @if ($c->replies->isNotEmpty())
                        <ul class="mt-2 space-y-2 border-l border-zinc-200 pl-3 dark:border-zinc-700">
                            @foreach ($c->replies as $reply)
                                <li>
                                    <span class="font-medium">{{ $reply->author->name }}</span>
                                    <span class="text-xs text-zinc-400"><x-time :at="$reply->created_at" /></span>
                                    @if ($reply->edited_at)
                                        <span class="text-xs text-zinc-400">{{ __('(edited)') }}</span>
                                    @endif
                                    @if ($this->editingCommentId === $reply->id)
                                        <form wire:submit="saveEdit" class="mt-2 space-y-2">
                                            <flux:textarea wire:model="editBody" rows="2" />
                                            <div class="flex gap-2">
                                                <flux:button type="submit" size="xs" variant="primary">{{ __('Save') }}</flux:button>
                                                <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                                            </div>
                                        </form>
                                    @else
                                        <p class="mt-1 rounded-md bg-zinc-50 p-2 dark:bg-zinc-800">{{ $this->displayComment($reply) }}</p>
                                    @endif
                                    @can('update', $reply)
                                        <flux:button size="xs" variant="ghost" wire:click="startEdit({{ $reply->id }})">{{ __('Edit') }}</flux:button>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($this->replyParentId === $c->id)
                        <form wire:submit="postReply" class="mt-2 space-y-2">
                            <flux:textarea wire:model="replyBody" :placeholder="__('Write a reply…')" rows="2" />
                            <flux:error name="replyBody" />
                            <div class="flex gap-2">
                                <flux:button type="submit" size="xs" variant="primary" data-test="submit-reply">{{ __('Reply') }}</flux:button>
                                <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @endif
                </li>
            @empty
                <li class="text-sm text-zinc-500">{{ __('No comments yet.') }}</li>
            @endforelse
        </ul>

        @can('comment', $submission)
            <form wire:submit="postComment" class="mt-4 space-y-2">
                <flux:textarea wire:model="comment" :placeholder="__('Add a comment. Use search below to mention someone…')" rows="2" />
                <flux:error name="comment" />
                <x-ui.assignee-picker
                    type="user"
                    query="mentionQuery"
                    :selected="$this->mentionedUsers"
                    :results="$this->mentionResults"
                    add="addMention"
                    remove="removeMention"
                    placeholder="Type two characters to mention someone…"
                />
                <flux:button type="submit" variant="ghost" size="sm">{{ __('Comment') }}</flux:button>
            </form>
        @endcan
    </flux:card>
