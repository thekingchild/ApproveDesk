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

    public function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return $minutes < 60
            ? $minutes.'m'
            : (intdiv($minutes, 60) < 24
                ? intdiv($minutes, 60).'h'
                : intdiv($minutes, 1440).'d '.intdiv($minutes % 1440, 60).'h');
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
                <flux:button :href="route('submissions.choose-type')" variant="primary" icon="plus" wire:navigate>
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
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <a href="{{ route('admin.users.index') }}" class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900" wire:navigate>{{ __('Manage users') }}</a>
                <a href="{{ route('admin.request-types.index') }}" class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900" wire:navigate>{{ __('Request types') }}</a>
                <a href="{{ route('admin.workflow-templates.index') }}" class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900" wire:navigate>{{ __('Workflow templates') }}</a>
                <a href="{{ route('admin.analytics.index') }}" class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900" wire:navigate>{{ __('Approval analytics') }}</a>
                <a href="{{ route('settings.approvals') }}" class="rounded-xl border border-zinc-200 bg-white p-4 font-semibold hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900" wire:navigate>{{ __('Approval settings') }}</a>
            </div>
        </section>

        @php($operations = $this->report['admin_metrics'])
        <section aria-labelledby="operations-health" data-test="operations-health">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <flux:heading id="operations-health" size="sm">{{ __('Approval operations health') }}</flux:heading>
                    <p class="mt-1 text-xs text-zinc-500">{{ __('Thirty-day workflow cohort. SLA compliance measures active work currently within its configured deadline.') }}</p>
                </div>
                <flux:link :href="route('admin.analytics.index')" wire:navigate>{{ __('Explore analytics') }}</flux:link>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                @foreach ([
                    ['label' => __('Awaiting me'), 'value' => $this->report['action_required'], 'detail' => __('assigned decisions')],
                    ['label' => __('Overdue'), 'value' => $operations['summary']['overdue'], 'detail' => __('active SLA breaches')],
                    ['label' => __('Median cycle time'), 'value' => $this->duration($operations['summary']['median_turnaround_minutes']), 'detail' => __('start to resolution')],
                    ['label' => __('Returned rate'), 'value' => $operations['summary']['returned_rate'] === null ? '—' : number_format($operations['summary']['returned_rate'], 1).'%', 'detail' => __(':count workflows returned', ['count' => $operations['summary']['returned']])],
                    ['label' => __('SLA compliance'), 'value' => $operations['summary']['sla_compliance'] === null ? '—' : number_format($operations['summary']['sla_compliance'], 1).'%', 'detail' => __('active work within SLA')],
                    ['label' => __('Bottleneck step'), 'value' => $operations['bottlenecks'][0]['step'] ?? '—', 'detail' => isset($operations['bottlenecks'][0]) ? $this->duration($operations['bottlenecks'][0]['score_minutes']) : __('not enough data')],
                ] as $metric)
                    <flux:card>
                        <p class="text-sm text-zinc-500">{{ $metric['label'] }}</p>
                        <p class="mt-2 truncate text-2xl font-semibold tabular-nums" title="{{ $metric['value'] }}">{{ $metric['value'] }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ $metric['detail'] }}</p>
                    </flux:card>
                @endforeach
            </div>

            <flux:card class="mt-4">
                <div>
                    <flux:heading size="sm">{{ __('Workload by approver') }}</flux:heading>
                    <p class="mt-1 text-xs text-zinc-500" role="note">
                        {{ trans_choice('{0} No active approvals are assigned.|{1} One active approval is assigned.|[2,*] :count active approvals are distributed across :people approvers.', collect($operations['workload_by_approver'])->sum('active'), ['count' => collect($operations['workload_by_approver'])->sum('active'), 'people' => count($operations['workload_by_approver'])]) }}
                    </p>
                </div>
                @php($workloadMaximum = max(1, collect($operations['workload_by_approver'])->max('active') ?? 1))
                <div class="mt-4 grid gap-6 lg:grid-cols-2">
                    <div class="space-y-3" role="img" aria-label="{{ __('Active approval workload by approver bar chart') }}">
                        @forelse ($operations['workload_by_approver'] as $workload)
                            <div class="grid grid-cols-[minmax(8rem,12rem)_1fr_3rem] items-center gap-2">
                                <span class="truncate text-sm">{{ $workload['approver'] }}</span>
                                <div class="h-3 overflow-hidden rounded-sm bg-zinc-100 dark:bg-zinc-700">
                                    <div class="h-full rounded-sm bg-blue-600" style="width: {{ max(2, ($workload['active'] / $workloadMaximum) * 100) }}%"></div>
                                </div>
                                <span class="text-right text-sm font-medium tabular-nums">{{ $workload['active'] }}</span>
                            </div>
                        @empty
                            <x-ui.empty-state :title="__('No active approver workload')" :description="__('New active approval assignments will appear here.')" icon="inbox" class="py-6" />
                        @endforelse
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">{{ __('Tabular alternative for approver workload chart') }}</caption>
                            <thead class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700"><tr><th class="pb-2">{{ __('Approver') }}</th><th class="pb-2 text-right">{{ __('Active') }}</th><th class="pb-2 text-right">{{ __('Overdue') }}</th></tr></thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @forelse ($operations['workload_by_approver'] as $workload)
                                    <tr><td class="py-2">{{ $workload['approver'] }}</td><td class="py-2 text-right tabular-nums">{{ $workload['active'] }}</td><td class="py-2 text-right tabular-nums">{{ $workload['overdue'] }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="py-4 text-center text-zinc-500">{{ __('No active assignments') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </flux:card>
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
                                <x-submission-status-badge :status="$submission->status" />
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
