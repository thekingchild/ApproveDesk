<?php

use App\Enums\NotificationFrequency;
use App\Models\Submission;
use App\Services\Workflow\AssigneeResolver;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Notifications')] class extends Component {
    use WithPagination;

    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return Auth::user()->notifications()->latest()->paginate(15);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    public function markRead(string $id): void
    {
        $notification = $this->findNotification($id);
        $notification->markAsRead();
        $this->refreshNotifications();
    }

    public function markUnread(string $id): void
    {
        $notification = $this->findNotification($id);
        $notification->markAsUnread();
        $this->refreshNotifications();
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);
        $this->refreshNotifications();
    }

    public function openNotification(string $id): void
    {
        $notification = $this->findNotification($id);
        $notification->markAsRead();
        $this->refreshNotifications();

        $submission = $this->submissionFor($notification);

        if ($submission && Gate::forUser(Auth::user())->allows('view', $submission)) {
            $this->redirect(route('submissions.show', $submission), navigate: true);
        }
    }

    public function titleFor(DatabaseNotification $notification): string
    {
        $reference = data_get($notification->data, 'reference_number');
        $kind = data_get($notification->data, 'kind');

        return match ($kind) {
            'action_required' => __('Approval required').($reference ? ' · '.$reference : ''),
            'delegated' => __('Approval delegated to you').($reference ? ' · '.$reference : ''),
            'decided' => __(str(data_get($notification->data, 'decision', 'updated'))->replace('_', ' ')->title()->toString()).($reference ? ' · '.$reference : ''),
            default => data_get($notification->data, 'title', __('Approval update')),
        };
    }

    public function descriptionFor(DatabaseNotification $notification): string
    {
        $title = data_get($notification->data, 'title');
        $kind = data_get($notification->data, 'kind');

        if ($title) {
            return $title;
        }

        return match ($kind) {
            'delegated' => __('Open the request to review the delegated approval.'),
            default => __('Open this update for more information.'),
        };
    }

    public function deliveryLabelFor(DatabaseNotification $notification): string
    {
        $frequency = NotificationFrequency::tryFrom(
            data_get($notification->data, 'delivery_frequency', Auth::user()->notification_frequency->value),
        ) ?? Auth::user()->notification_frequency;

        return match ($frequency) {
            NotificationFrequency::Immediate => __('In app + immediate email'),
            NotificationFrequency::Daily => __('In app + daily digest'),
            NotificationFrequency::Weekly => __('In app + weekly digest'),
            NotificationFrequency::InAppOnly => __('In app only'),
        };
    }

    public function iconFor(DatabaseNotification $notification): string
    {
        return match (data_get($notification->data, 'kind')) {
            'action_required' => 'inbox-arrow-down',
            'delegated' => 'user-plus',
            'decided' => 'check-circle',
            default => 'bell',
        };
    }

    private function findNotification(string $id): DatabaseNotification
    {
        return Auth::user()->notifications()->findOrFail($id);
    }

    /**
     * Requests the viewer may open, for every notification on this page, loaded
     * and authorised in one pass instead of several queries per row (RV-PERF-05).
     *
     * @return array<int, Submission|null> null when the request is gone or not viewable
     */
    #[Computed]
    public function viewableSubmissions(): array
    {
        $ids = collect($this->notifications->items())
            ->map(fn (DatabaseNotification $notification): mixed => data_get($notification->data, 'submission_id'))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $user = Auth::user();

        $viewable = AssigneeResolver::batch(fn (): array => Submission::query()
            ->with(['workflowInstance.currentStep', 'workflowInstance.delegations', 'workflowInstance.actions', 'watchers'])
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (Submission $submission): bool => Gate::forUser($user)->allows('view', $submission))
            ->keyBy('id')
            ->all());

        return array_replace(array_fill_keys($ids, null), $viewable);
    }

    public function submissionFor(DatabaseNotification $notification): ?Submission
    {
        $id = data_get($notification->data, 'submission_id');
        if (! is_numeric($id)) {
            return null;
        }

        if (array_key_exists((int) $id, $this->viewableSubmissions)) {
            return $this->viewableSubmissions[(int) $id];
        }

        // Not on the current page (e.g. opened from elsewhere): check it directly.
        $submission = Submission::query()->find((int) $id);

        return $submission && Gate::forUser(Auth::user())->allows('view', $submission)
            ? $submission
            : null;
    }

    private function refreshNotifications(): void
    {
        unset($this->notifications, $this->unreadCount);
        $this->dispatch('notifications-changed');
    }
}; ?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[
        ['label' => __('Dashboard'), 'url' => route('dashboard')],
        ['label' => __('Notifications')],
    ]" :back="route('dashboard')" back-label="Back to dashboard" />

    <x-ui.page-header
        :title="__('Notifications')"
        :description="__('Approval updates and decisions, kept together in one place.')"
    >
        @if ($this->unreadCount)
            <x-slot:actions>
                <flux:button variant="outline" icon="check" wire:click="markAllRead">{{ __('Mark all as read') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-zinc-600 dark:text-zinc-300">
            {{ trans_choice('{0} You are all caught up|{1} :count unread notification|[2,*] :count unread notifications', $this->unreadCount, ['count' => $this->unreadCount]) }}
        </p>
        <flux:link :href="route('notifications.edit')" wire:navigate>{{ __('Delivery preferences') }}</flux:link>
    </div>

    <flux:card class="p-0!">
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700" data-test="notification-list">
            @forelse ($this->notifications as $notification)
                <li @class(['bg-blue-50/60 dark:bg-blue-950/20' => $notification->unread()])>
                    <div class="flex gap-3 p-4 sm:p-5">
                        <div @class([
                            'ad-empty-state__icon shrink-0',
                            'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' => $notification->unread(),
                        ])>
                            <flux:icon :icon="$this->iconFor($notification)" class="size-5" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p @class(['font-semibold text-zinc-950 dark:text-white', 'font-medium' => $notification->read()])>{{ $this->titleFor($notification) }}</p>
                                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $this->descriptionFor($notification) }}</p>
                                </div>
                                @if ($notification->unread())
                                    <span class="mt-1 size-2 rounded-full bg-blue-600" aria-label="{{ __('Unread') }}"></span>
                                @endif
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-zinc-600 dark:text-zinc-300">
                                <time datetime="{{ $notification->created_at?->toIso8601String() }}">{{ $notification->created_at?->diffForHumans() }}</time>
                                <span>·</span>
                                <span>{{ $this->deliveryLabelFor($notification) }}</span>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($this->submissionFor($notification))
                                    <flux:button size="sm" variant="primary" wire:click="openNotification('{{ $notification->id }}')">{{ __('Open request') }}</flux:button>
                                @endif
                                @if ($notification->unread())
                                    <flux:button size="sm" variant="ghost" wire:click="markRead('{{ $notification->id }}')">{{ __('Mark as read') }}</flux:button>
                                @else
                                    <flux:button size="sm" variant="ghost" wire:click="markUnread('{{ $notification->id }}')">{{ __('Mark as unread') }}</flux:button>
                                @endif
                            </div>
                        </div>
                    </div>
                </li>
            @empty
                <li>
                    <x-ui.empty-state
                        :title="__('No notifications yet')"
                        :description="__('Approval assignments, decisions, returns, and delegations will appear here.')"
                        icon="bell"
                    />
                </li>
            @endforelse
        </ul>
    </flux:card>

    {{ $this->notifications->links() }}
</div>
