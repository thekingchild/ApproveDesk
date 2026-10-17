<?php

use App\Actions\Collaboration\FollowSubmissionAction;
use App\Actions\Collaboration\UnfollowSubmissionAction;
use App\Actions\Collaboration\UpdateWatcherPreferencesAction;
use App\Exceptions\CollaborationException;
use App\Models\User;
use App\Services\Workflow\AssigneeDirectory;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use App\Models\Submission;
use Livewire\Component;

/*
 * Followers card of the request page (ADR 0006 split, RV-ARC-02).
 */
new class extends Component {
    public Submission $submission;

    public string $watcherQuery = '';

    public bool $notifyComments = true;

    public bool $notifyStatus = true;

    public function mount(Submission $submission): void
    {
        $this->authorize('view', $submission);
        $this->submission = $submission->loadMissing(['watchers.user']);
        $this->loadPreferences();
    }

    public function hydrate(): void
    {
        $this->submission->loadMissing(['watchers.user']);
    }

    public function follow(): void
    {
        $this->authorize('follow', $this->submission);
        try {
            app(FollowSubmissionAction::class)->execute(
                $this->submission,
                Auth::user(),
                Auth::user(),
                notifyComments: $this->notifyComments,
                notifyStatus: $this->notifyStatus,
            );
        } catch (CollaborationException $exception) {
            Flux::toast($exception->userMessage(), variant: 'danger');

            return;
        }

        $this->refreshCollaboration();
        Flux::toast('You are following this request.', variant: 'success');
    }

    public function unfollow(): void
    {
        $this->authorize('view', $this->submission);
        try {
            app(UnfollowSubmissionAction::class)->execute($this->submission, Auth::user(), Auth::user());
        } catch (CollaborationException $exception) {
            Flux::toast($exception->userMessage(), variant: 'danger');

            return;
        }

        $this->refreshCollaboration();
        Flux::toast('You unfollowed this request.', variant: 'success');
    }

    public function addWatcher(int $userId): void
    {
        $this->authorize('addWatcher', $this->submission);
        try {
            app(FollowSubmissionAction::class)->execute(
                $this->submission,
                Auth::user(),
                User::query()->findOrFail($userId),
            );
        } catch (CollaborationException $exception) {
            Flux::toast($exception->userMessage(), variant: 'danger');

            return;
        }

        $this->watcherQuery = '';
        $this->refreshCollaboration();
        Flux::toast('Follower added.', variant: 'success');
    }

    public function removeWatcher(int $userId): void
    {
        $this->authorize('addWatcher', $this->submission);
        try {
            app(UnfollowSubmissionAction::class)->execute(
                $this->submission,
                Auth::user(),
                User::query()->findOrFail($userId),
            );
        } catch (CollaborationException $exception) {
            Flux::toast($exception->userMessage(), variant: 'danger');

            return;
        }

        $this->refreshCollaboration();
    }

    public function saveWatcherPreferences(): void
    {
        $this->authorize('follow', $this->submission);
        try {
            app(UpdateWatcherPreferencesAction::class)->execute(
                $this->submission,
                Auth::user(),
                $this->notifyComments,
                $this->notifyStatus,
            );
        } catch (CollaborationException $exception) {
            Flux::toast($exception->userMessage(), variant: 'danger');

            return;
        }

        Flux::toast('Follow preferences saved.', variant: 'success');
    }

    #[Computed]
    public function watcherResults(): \Illuminate\Support\Collection
    {
        $exclude = $this->submission->watchers->pluck('user_id')->all();
        $exclude[] = $this->submission->submitter_id;

        return app(AssigneeDirectory::class)->search('user', $this->watcherQuery, $exclude);
    }

    /** Comments can add followers (an author follows what they comment on). */
    #[On('submission-followers-changed')]
    public function refreshFollowers(): void
    {
        $this->authorize('view', $this->submission);
        $this->refreshCollaboration();
    }

    private function refreshCollaboration(): void
    {
        $this->submission->load('watchers.user');
        unset($this->watcherResults);
        $this->loadPreferences();
    }

    private function loadPreferences(): void
    {
        $mine = $this->submission->watchers->firstWhere('user_id', Auth::id());
        if ($mine) {
            $this->notifyComments = $mine->notify_comments;
            $this->notifyStatus = $mine->notify_status;
        }
    }
}; ?>

    <flux:card data-test="watchers-card">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="sm">{{ __('Followers') }}</flux:heading>
                <flux:subheading>{{ __('Followers can open this request. You can unfollow yourself at any time.') }}</flux:subheading>
            </div>
            @if ($submission->isWatchedBy(auth()->user()))
                <flux:button size="sm" variant="ghost" wire:click="unfollow" data-test="unfollow">{{ __('Unfollow') }}</flux:button>
            @else
                <flux:button size="sm" variant="subtle" wire:click="follow" data-test="follow">{{ __('Follow') }}</flux:button>
            @endif
        </div>

        @if ($submission->isWatchedBy(auth()->user()))
            <form wire:submit="saveWatcherPreferences" class="mt-3 flex flex-wrap items-end gap-3">
                <flux:checkbox wire:model="notifyComments" :label="__('Notify me of comments')" />
                <flux:checkbox wire:model="notifyStatus" :label="__('Notify me of status changes')" />
                <flux:button type="submit" size="xs" variant="ghost">{{ __('Save') }}</flux:button>
            </form>
        @endif

        <ul class="mt-3 flex flex-wrap gap-2" data-test="watcher-list">
            @forelse ($submission->watchers as $watcher)
                <li class="inline-flex items-center gap-1 rounded-full border border-zinc-300 bg-zinc-50 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-800">
                    {{ $watcher->user->name }}
                    @if (auth()->id() === $watcher->user_id || auth()->user()?->can('addWatcher', $submission))
                        <button type="button" class="text-zinc-500" wire:click="removeWatcher({{ $watcher->user_id }})" aria-label="{{ __('Remove :name', ['name' => $watcher->user->name]) }}">×</button>
                    @endif
                </li>
            @empty
                <li class="text-sm text-zinc-500">{{ __('No followers yet.') }}</li>
            @endforelse
        </ul>

        @can('addWatcher', $submission)
            <div class="mt-4">
                <x-ui.assignee-picker
                    type="user"
                    query="watcherQuery"
                    :selected="collect()"
                    :results="$this->watcherResults"
                    add="addWatcher"
                    remove="removeWatcher"
                    :hide-empty="true"
                    placeholder="Type a name to add a follower…"
                />
            </div>
        @endcan
    </flux:card>
