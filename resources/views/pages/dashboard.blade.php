<?php

use App\Services\ReportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /** @return array<string, mixed> */
    #[Computed]
    public function report(): array
    {
        return app(ReportService::class)->dashboard(Auth::user());
    }
} ?>

@php
    $homeDescriptions = [
        'administrator' => __('Monitor organization activity and manage approval operations.'),
        'approver' => __('Decide what needs your attention and keep assigned approvals moving.'),
        'requester' => __('Continue drafts and track the requests you have submitted.'),
        'mixed' => __('Act on assigned approvals, then continue your own requests.'),
        'viewer' => __('Track the requests you are allowed to view.'),
    ];
@endphp

<div class="space-y-6" data-home-mode="{{ $this->report['home_mode'] }}">
    <x-ui.page-header
        :title="__('Welcome back, :name', ['name' => str(auth()->user()->name)->before(' ')])"
        :description="$homeDescriptions[$this->report['home_mode']]"
    >
        @if ($this->report['can_submit'])
            <x-slot:actions>
                <flux:button :href="route('submissions.create')" variant="primary" icon="plus" wire:navigate>
                    {{ __('New submission') }}
                </flux:button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($this->report['action_required'] > 0)
        <section aria-labelledby="priority-approvals" data-test="priority-approvals">
            <flux:card class="border-blue-200 bg-blue-50/60 dark:border-blue-800 dark:bg-blue-950/20">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <flux:heading id="priority-approvals" size="sm">{{ trans_choice('{1} :count approval needs your decision|[2,*] :count approvals need your decision', $this->report['action_required'], ['count' => $this->report['action_required']]) }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Review the nearest deadlines first.') }}</flux:text>
                    </div>
                    <flux:button :href="route('approvals.inbox')" variant="primary" icon="inbox" wire:navigate>{{ __('Open approval inbox') }}</flux:button>
                </div>

                <ul class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->report['action_required_items'] as $instance)
                        <li>
                            <a href="{{ route('submissions.show', $instance->submission) }}" class="block rounded-lg border border-blue-200 bg-white p-3 hover:border-blue-400 dark:border-blue-800 dark:bg-zinc-900 dark:hover:border-blue-600" wire:navigate>
                                <span class="block font-semibold text-zinc-950 dark:text-white">{{ Str::limit($instance->submission->title, 48) }}</span>
                                <span class="mt-1 block text-xs text-zinc-600 dark:text-zinc-300">{{ $instance->submission->reference_number }} · {{ $instance->submission->submitter->name }}</span>
                                @if ($instance->deadlineAt())
                                    <span @class(['mt-2 block text-xs font-medium', 'text-red-700 dark:text-red-300' => $instance->isOverdue(), 'text-zinc-600 dark:text-zinc-300' => ! $instance->isOverdue()])>
                                        {{ $instance->isOverdue() ? __('Overdue') : __('Due :time', ['time' => $instance->deadlineAt()->diffForHumans()]) }}
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        </section>
    @elseif ($this->report['can_submit'] && $this->report['requester_attention'] > 0)
        <section aria-labelledby="priority-requests" data-test="priority-requests">
            <flux:card class="border-amber-200 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-950/20">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <flux:heading id="priority-requests" size="sm">{{ __('Continue your requests') }}</flux:heading>
                        <flux:text class="mt-1">{{ trans_choice('{1} :count draft or returned request needs attention|[2,*] :count drafts or returned requests need attention', $this->report['requester_attention'], ['count' => $this->report['requester_attention']]) }}</flux:text>
                    </div>
                    <flux:button :href="route('submissions.index')" variant="primary" wire:navigate>{{ __('View my requests') }}</flux:button>
                </div>
            </flux:card>
        </section>
    @endif

    @if ($this->report['home_mode'] === 'administrator')
        <section aria-labelledby="admin-shortcuts" data-test="administrator-home">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading id="admin-shortcuts" size="sm">{{ __('Administration') }}</flux:heading>
                <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Organization controls') }}</span>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <a href="{{ route('admin.users.index') }}" class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900" wire:navigate>{{ __('Manage users') }}</a>
                <a href="{{ route('admin.workflow-templates.index') }}" class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900" wire:navigate>{{ __('Workflow templates') }}</a>
                <a href="{{ route('admin.analytics.index') }}" class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900" wire:navigate>{{ __('Approval analytics') }}</a>
                <a href="{{ route('settings.approvals') }}" class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900" wire:navigate>{{ __('Approval settings') }}</a>
            </div>
        </section>
    @endif

    <section aria-labelledby="submission-summary">
        <div class="mb-3 flex items-center justify-between">
            <flux:heading id="submission-summary" size="sm">
                {{ $this->report['organization'] ? __('Organization submissions') : __('My submissions') }}
            </flux:heading>
            <flux:link :href="route('submissions.index')" wire:navigate>{{ __('View submissions') }}</flux:link>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => __('Total'), 'value' => $this->report['counts']['total'], 'color' => 'zinc'],
                ['label' => __('In review'), 'value' => $this->report['counts']['in_review'], 'color' => 'blue'],
                ['label' => __('Approved'), 'value' => $this->report['counts']['approved'], 'color' => 'lime'],
                ['label' => __('Rejected'), 'value' => $this->report['counts']['rejected'], 'color' => 'red'],
            ] as $metric)
                <flux:card>
                    <p class="text-sm text-zinc-500">{{ $metric['label'] }}</p>
                    <div class="mt-2 flex items-end justify-between">
                        <p class="text-3xl font-semibold tabular-nums">{{ $metric['value'] }}</p>
                        <flux:badge :color="$metric['color']">{{ $metric['label'] }}</flux:badge>
                    </div>
                </flux:card>
            @endforeach
        </div>
    </section>

    @if ($this->report['can_approve'])
        <section aria-labelledby="approval-summary">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading id="approval-summary" size="sm">{{ __('My approval work') }}</flux:heading>
                <flux:link :href="route('approvals.inbox')" wire:navigate>{{ __('Open approval inbox') }}</flux:link>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ([
                    ['label' => __('To action'), 'value' => $this->report['action_required'], 'color' => 'blue'],
                    ['label' => __('Pending others'), 'value' => $this->report['pending_others'], 'color' => 'amber'],
                    ['label' => __('Completed'), 'value' => $this->report['completed_approvals'], 'color' => 'lime'],
                ] as $metric)
                    <flux:card>
                        <div class="flex items-center justify-between">
                            <p class="text-sm text-zinc-500">{{ $metric['label'] }}</p>
                            <flux:badge :color="$metric['color']">{{ $metric['value'] }}</flux:badge>
                        </div>
                        <p class="mt-2 text-3xl font-semibold tabular-nums">{{ $metric['value'] }}</p>
                    </flux:card>
                @endforeach
            </div>
        </section>
    @endif

    <flux:card>
        <div class="flex items-center justify-between">
            <flux:heading size="sm">{{ __('Recently updated') }}</flux:heading>
            <flux:badge color="zinc">{{ $this->report['recent']->count() }}</flux:badge>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700">
                    <tr>
                        <th class="pb-2 font-medium">{{ __('Reference') }}</th>
                        <th class="pb-2 font-medium">{{ __('Title') }}</th>
                        @if ($this->report['organization'])<th class="pb-2 font-medium">{{ __('Submitter') }}</th>@endif
                        <th class="pb-2 font-medium">{{ __('Status') }}</th>
                        <th class="pb-2 text-right font-medium">{{ __('Updated') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->report['recent'] as $submission)
                        <tr>
                            <td class="py-3 font-mono text-xs text-zinc-500">{{ $submission->reference_number }}</td>
                            <td class="py-3">
                                <flux:link :href="route('submissions.show', $submission)" wire:navigate>{{ Str::limit($submission->title, 55) }}</flux:link>
                            </td>
                            @if ($this->report['organization'])<td class="py-3">{{ $submission->submitter->name }}</td>@endif
                            <td class="py-3">
                                <flux:badge color="{{ ['draft' => 'zinc', 'pending' => 'blue', 'in_review' => 'blue', 'approved' => 'lime', 'rejected' => 'red', 'returned' => 'amber', 'withdrawn' => 'zinc'][$submission->status->value] ?? 'zinc' }}">
                                    {{ str_replace('_', ' ', $submission->status->value) }}
                                </flux:badge>
                            </td>
                            <td class="py-3 text-right text-zinc-500">{{ $submission->updated_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state
                                    :title="__('No submissions yet')"
                                    :description="__('New and recently updated submissions will appear here.')"
                                    icon="document-text"
                                    class="py-8"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
