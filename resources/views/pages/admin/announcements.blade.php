<?php

use App\Actions\Announcements\ArchiveAnnouncementAction;
use App\Actions\Announcements\PublishAnnouncementAction;
use App\Actions\Announcements\SaveAnnouncementAction;
use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementDisplayMode;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\Role;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Announcements')] class extends Component
{
    #[Locked]
    public ?int $editingId = null;

    public string $title = '';

    public string $message = '';

    public string $audience = 'all';

    /** @var list<int|string> */
    public array $roleIds = [];

    public string $displayMode = 'sticky';

    public function mount(): void
    {
        $this->authorize('viewAny', Announcement::class);
    }

    #[Computed]
    public function announcements(): \Illuminate\Database\Eloquent\Collection
    {
        return Announcement::query()
            ->with('creator')
            ->withCount('receipts')
            ->latest('published_at')
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function roles(): \Illuminate\Database\Eloquent\Collection
    {
        return Role::query()->orderBy('name')->get();
    }

    public function createAnnouncement(): void
    {
        $this->authorize('create', Announcement::class);
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $announcement = Announcement::query()->findOrFail($id);
        $this->authorize('update', $announcement);
        $this->resetValidation();
        $this->editingId = $announcement->id;
        $this->title = $announcement->title;
        $this->message = $announcement->message;
        $this->audience = $announcement->audience->value;
        $this->roleIds = $announcement->role_ids ?? [];
        $this->displayMode = $announcement->display_mode->value;
    }

    public function save(SaveAnnouncementAction $action): void
    {
        $target = $this->editingId ? Announcement::query()->findOrFail($this->editingId) : null;
        $actor = Auth::user();
        assert($actor instanceof User);

        $saved = $action->execute($target, [
            'title' => $this->title,
            'message' => $this->message,
            'audience' => $this->audience,
            'role_ids' => $this->roleIds,
            'display_mode' => $this->displayMode,
        ], $actor);

        $corrected = $target?->status === AnnouncementStatus::Published;
        $this->edit($saved->id);
        unset($this->announcements);
        Flux::toast($corrected ? __('Published announcement corrected and shown again.') : __('Announcement saved.'), variant: 'success');
    }

    public function publish(int $id, PublishAnnouncementAction $action): void
    {
        $announcement = Announcement::query()->findOrFail($id);
        $actor = Auth::user();
        assert($actor instanceof User);
        $action->execute($announcement, $actor);
        unset($this->announcements);
        Flux::toast(__('Announcement published.'), variant: 'success');
    }

    public function archive(int $id, ArchiveAnnouncementAction $action): void
    {
        $announcement = Announcement::query()->findOrFail($id);
        $actor = Auth::user();
        assert($actor instanceof User);
        $action->execute($announcement, $actor);
        if ($this->editingId === $id) {
            $this->resetForm();
        }
        unset($this->announcements);
        Flux::toast(__('Announcement archived.'), variant: 'success');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->title = '';
        $this->message = '';
        $this->audience = AnnouncementAudience::All->value;
        $this->roleIds = [];
        $this->displayMode = AnnouncementDisplayMode::Sticky->value;
    }
}; ?>

<div class="space-y-8">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Announcements')]]" :back="route('dashboard')" back-label="Back to dashboard" />
    <x-ui.page-header :title="__('Announcements')" :description="__('Publish safe in-app notices to everyone or selected roles.')" help-article="announcements" />

    <div class="grid gap-8 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <flux:card>
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="sm">{{ $editingId ? __('Edit announcement') : __('New announcement') }}</flux:heading>
                @if ($editingId)
                    <flux:button size="sm" variant="ghost" wire:click="createAnnouncement">{{ __('Start new') }}</flux:button>
                @endif
            </div>

            <form wire:submit="save" class="mt-5 space-y-4">
                <flux:input wire:model="title" :label="__('Title')" maxlength="160" required />
                <flux:error name="title" />
                <div
                    class="space-y-2"
                    x-data="announcementFormatter"
                    data-link-prompt="{{ __('Enter an http, https, mailto, page, or anchor link') }}"
                    data-link-text="{{ __('Link text') }}"
                    data-list-item="{{ __('List item') }}"
                >
                    <div class="flex flex-wrap gap-1 rounded-lg border border-zinc-200 bg-zinc-50 p-1.5 dark:border-zinc-700 dark:bg-zinc-800/60" role="group" aria-label="{{ __('Message formatting') }}" data-test="announcement-format-toolbar">
                        <button type="button" class="min-h-9 min-w-9 rounded-md px-2 font-bold hover:bg-white dark:hover:bg-zinc-700" aria-label="{{ __('Bold') }}" title="{{ __('Bold') }}" x-on:click="replace('<strong>', '</strong>', '{{ __('bold text') }}')">B</button>
                        <button type="button" class="min-h-9 min-w-9 rounded-md px-2 italic hover:bg-white dark:hover:bg-zinc-700" aria-label="{{ __('Italic') }}" title="{{ __('Italic') }}" x-on:click="replace('<em>', '</em>', '{{ __('italic text') }}')">I</button>
                        <button type="button" class="min-h-9 min-w-9 rounded-md px-2 underline hover:bg-white dark:hover:bg-zinc-700" aria-label="{{ __('Underline') }}" title="{{ __('Underline') }}" x-on:click="replace('<u>', '</u>', '{{ __('underlined text') }}')">U</button>
                        <span class="mx-1 w-px self-stretch bg-zinc-200 dark:bg-zinc-700" aria-hidden="true"></span>
                        <button type="button" class="min-h-9 rounded-md px-2 text-sm font-medium hover:bg-white dark:hover:bg-zinc-700" aria-label="{{ __('Bulleted list') }}" title="{{ __('Bulleted list') }}" x-on:click="list('ul')">• {{ __('List') }}</button>
                        <button type="button" class="min-h-9 rounded-md px-2 text-sm font-medium hover:bg-white dark:hover:bg-zinc-700" aria-label="{{ __('Numbered list') }}" title="{{ __('Numbered list') }}" x-on:click="list('ol')">1. {{ __('List') }}</button>
                        <button type="button" class="min-h-9 rounded-md px-2 text-sm font-medium hover:bg-white dark:hover:bg-zinc-700" aria-label="{{ __('Insert link') }}" title="{{ __('Insert link') }}" x-on:click="link()">{{ __('Link') }}</button>
                    </div>
                    <flux:textarea x-ref="message" wire:model="message" :label="__('Message')" rows="8" :description="__('Select text, then use the formatting buttons. Safe links and basic formatting are kept; scripts and unsafe markup are removed.')" required />
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Formatting is stored as safe HTML and shown as styled text after saving.') }}</p>
                </div>
                <flux:error name="message" />

                <flux:select wire:model.live="audience" :label="__('Audience')">
                    <flux:select.option value="all">{{ __('All active users') }}</flux:select.option>
                    <flux:select.option value="roles">{{ __('Selected roles') }}</flux:select.option>
                </flux:select>
                @if ($audience === 'roles')
                    <fieldset class="space-y-2">
                        <legend class="text-sm font-medium">{{ __('Roles') }}</legend>
                        @foreach ($this->roles as $role)
                            <flux:checkbox wire:model="roleIds" value="{{ $role->id }}" :label="$role->name" />
                        @endforeach
                        <flux:error name="role_ids" />
                    </fieldset>
                @endif

                <flux:select wire:model="displayMode" :label="__('Display mode')">
                    <flux:select.option value="sticky">{{ __('Sticky — visible until archived') }}</flux:select.option>
                    <flux:select.option value="acknowledge">{{ __('Acknowledge — visible until marked as read') }}</flux:select.option>
                    <flux:select.option value="show_once">{{ __('Show once — hidden after first display') }}</flux:select.option>
                </flux:select>
                <flux:error name="display_mode" />

                <flux:button type="submit" variant="primary">{{ __('Save announcement') }}</flux:button>
            </form>
        </flux:card>

        <div class="space-y-4">
            @forelse ($this->announcements as $announcement)
                <flux:card wire:key="announcement-{{ $announcement->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:heading size="sm">{{ $announcement->title }}</flux:heading>
                                <flux:badge>{{ ucfirst($announcement->status->value) }}</flux:badge>
                            </div>
                            <flux:text class="mt-1">
                                {{ $announcement->audience === AnnouncementAudience::All ? __('All active users') : trans_choice(':count selected role|:count selected roles', count($announcement->role_ids ?? []), ['count' => count($announcement->role_ids ?? [])]) }}
                                · {{ match ($announcement->display_mode) { AnnouncementDisplayMode::Sticky => __('Sticky'), AnnouncementDisplayMode::Acknowledge => __('Acknowledge'), AnnouncementDisplayMode::ShowOnce => __('Show once') } }}
                                · {{ trans_choice(':count receipt|:count receipts', $announcement->receipts_count, ['count' => $announcement->receipts_count]) }}
                            </flux:text>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" wire:click="edit({{ $announcement->id }})">{{ __('Edit') }}</flux:button>
                            @if ($announcement->status !== AnnouncementStatus::Published)
                                <flux:button size="sm" variant="primary" wire:click="publish({{ $announcement->id }})">{{ $announcement->status === AnnouncementStatus::Archived ? __('Republish') : __('Publish') }}</flux:button>
                            @endif
                            @if ($announcement->status === AnnouncementStatus::Published)
                                @if ($announcement->display_mode === AnnouncementDisplayMode::Sticky)
                                    <flux:button size="sm" variant="danger" wire:click="archive({{ $announcement->id }})" wire:confirm="{{ __('End this sticky announcement for everyone?') }}">{{ __('Archive') }}</flux:button>
                                @else
                                    <flux:button size="sm" variant="danger" wire:click="archive({{ $announcement->id }})">{{ __('Archive') }}</flux:button>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="prose prose-sm mt-4 max-w-none dark:prose-invert">{!! $announcement->safeMessage() !!}</div>
                    <p class="mt-3 text-xs text-zinc-500">
                        {{ __('Created by :name', ['name' => $announcement->creator->name]) }}
                        @if ($announcement->published_at)
                            · {{ __('Published :time', ['time' => $announcement->published_at->diffForHumans()]) }}
                        @endif
                    </p>
                </flux:card>
            @empty
                <div class="ad-empty-state">
                    <p class="font-semibold">{{ __('No announcements yet') }}</p>
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Create a draft, review its audience and display mode, then publish it.') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
