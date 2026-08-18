<?php

use App\Services\AnalyticsService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Approval analytics')] class extends Component {
    #[Url]
    public int $days = 30;

    public function updatedDays(): void
    {
        if (! in_array($this->days, AnalyticsService::PERIODS, true)) {
            $this->days = 30;
        }

        unset($this->report);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function report(): array
    {
        return app(AnalyticsService::class)->dashboard($this->days);
    }

    public function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return 'Not enough data';
        }

        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return $hours.'h'.($remainingMinutes > 0 ? ' '.$remainingMinutes.'m' : '');
        }

        $days = intdiv($hours, 24);
        $remainingHours = $hours % 24;

        return $days.'d'.($remainingHours > 0 ? ' '.$remainingHours.'h' : '');
    }
} ?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Analytics')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ __('Approval analytics') }}</flux:heading>
            <flux:subheading>{{ __('Organization-wide workflows started between :from and :to. Calculated live at :time.', ['from' => $this->report['from']->format('M j, Y'), 'to' => $this->report['to']->format('M j, Y'), 'time' => now()->format('M j, Y g:i A')]) }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="days" :label="__('Reporting period')" class="w-40">
                <flux:select.option value="7">{{ __('Last 7 days') }}</flux:select.option>
                <flux:select.option value="30">{{ __('Last 30 days') }}</flux:select.option>
                <flux:select.option value="90">{{ __('Last 90 days') }}</flux:select.option>
            </flux:select>
            <flux:dropdown>
                <flux:button icon="arrow-down-tray" icon-trailing="chevron-down">{{ __('Export') }}</flux:button>
                <flux:menu>
                    <flux:menu.item :href="route('admin.analytics.export', ['days' => $days, 'format' => 'csv'])" icon="document-text">{{ __('CSV data') }}</flux:menu.item>
                    <flux:menu.item :href="route('admin.analytics.export', ['days' => $days, 'format' => 'xlsx'])" icon="table-cells">{{ __('Excel workbook') }}</flux:menu.item>
                    <flux:menu.item :href="route('admin.analytics.export', ['days' => $days, 'format' => 'pdf'])" icon="document-arrow-down">{{ __('PDF report') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <section aria-labelledby="analytics-summary">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <flux:heading id="analytics-summary" size="sm">{{ __('Workflow performance') }}</flux:heading>
                <p class="mt-1 text-xs text-zinc-500">{{ __('Approval rate uses resolved workflows only; turnaround runs from workflow start to final resolution.') }}</p>
            </div>
            <p class="text-xs text-zinc-500">{{ __('Cohort: :count workflows', ['count' => $this->report['summary']['started']]) }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => __('Started'), 'value' => $this->report['summary']['started'], 'detail' => __('workflows')],
                ['label' => __('Active'), 'value' => $this->report['summary']['active'], 'detail' => __('including on hold')],
                ['label' => __('Approval rate'), 'value' => $this->report['summary']['approval_rate'] === null ? '—' : number_format($this->report['summary']['approval_rate'], 1).'%', 'detail' => __(':approved approved · :rejected rejected', ['approved' => $this->report['summary']['approved'], 'rejected' => $this->report['summary']['rejected']])],
                ['label' => __('Median turnaround'), 'value' => $this->duration($this->report['summary']['median_turnaround_minutes']), 'detail' => __('Average :duration', ['duration' => $this->duration($this->report['summary']['average_turnaround_minutes'])])],
                ['label' => __('Overdue now'), 'value' => $this->report['summary']['overdue'], 'detail' => __('active SLA breaches')],
                ['label' => __('Returned rate'), 'value' => $this->report['summary']['returned_rate'] === null ? '—' : number_format($this->report['summary']['returned_rate'], 1).'%', 'detail' => __(':count workflows returned', ['count' => $this->report['summary']['returned']])],
                ['label' => __('SLA compliance'), 'value' => $this->report['summary']['sla_compliance'] === null ? '—' : number_format($this->report['summary']['sla_compliance'], 1).'%', 'detail' => __('active work within SLA')],
            ] as $metric)
                <flux:card>
                    <p class="text-sm text-zinc-500">{{ $metric['label'] }}</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums">{{ $metric['value'] }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ $metric['detail'] }}</p>
                </flux:card>
            @endforeach
        </div>
    </section>

    <flux:card>
        @php
            $dailyMaximum = max(1, collect($this->report['daily'])->map(fn ($day) => max($day['started'], $day['resolved']))->max());
            $labelEvery = max(1, (int) ceil(count($this->report['daily']) / 7));
        @endphp
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="sm">{{ __('Daily workflow activity') }}</flux:heading>
                <p class="mt-1 text-xs text-zinc-500">{{ __('Starts versus resolutions by calendar day · :days-day cohort', ['days' => $days]) }}</p>
                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300" role="note">
                    {{ __('Chart summary: :started workflows started and :resolved resolved. Peak daily activity was :peak.', ['started' => collect($this->report['daily'])->sum('started'), 'resolved' => collect($this->report['daily'])->sum('resolved'), 'peak' => $dailyMaximum]) }}
                </p>
            </div>
            <div class="flex gap-4 text-xs text-zinc-500" aria-label="{{ __('Chart legend') }}">
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-blue-600"></span>{{ __('Started') }}</span>
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm border border-amber-700 bg-amber-200 dark:bg-amber-800"></span>{{ __('Resolved') }}</span>
            </div>
        </div>

        <div class="mt-5 overflow-x-auto pb-2">
            <div class="flex h-56 min-w-[44rem] items-end gap-1 border-b border-zinc-300 dark:border-zinc-600" role="img" aria-label="{{ __('Daily started and resolved workflow bar chart') }}">
                @foreach ($this->report['daily'] as $index => $day)
                    <div class="group relative flex h-full min-w-0 flex-1 items-end justify-center gap-px" title="{{ $day['label'] }}: {{ $day['started'] }} started, {{ $day['resolved'] }} resolved">
                        <div class="w-2/5 min-h-px rounded-t-sm bg-blue-600" style="height: {{ max(1, ($day['started'] / $dailyMaximum) * 100) }}%"></div>
                        <div class="w-2/5 min-h-px rounded-t-sm border border-amber-700 bg-amber-200 dark:bg-amber-800" style="height: {{ max(1, ($day['resolved'] / $dailyMaximum) * 100) }}%"></div>
                        @if ($index % $labelEvery === 0 || $loop->last)
                            <span class="absolute -bottom-5 whitespace-nowrap text-[10px] text-zinc-500">{{ $day['label'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <details class="mt-6 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            <summary class="cursor-pointer text-sm font-medium">{{ __('View daily activity data table') }}</summary>
            <div class="mt-3 max-h-80 overflow-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ __('Tabular alternative for daily workflow activity chart') }}</caption>
                    <thead class="sticky top-0 border-b border-zinc-200 bg-white text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900"><tr><th class="pb-2">{{ __('Date') }}</th><th class="pb-2 text-right">{{ __('Started') }}</th><th class="pb-2 text-right">{{ __('Resolved') }}</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->report['daily'] as $day)
                            <tr><td class="py-2">{{ $day['date'] }}</td><td class="py-2 text-right tabular-nums">{{ $day['started'] }}</td><td class="py-2 text-right tabular-nums">{{ $day['resolved'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </flux:card>

    <flux:card>
        @php $bottleneckMaximum = max(1, collect($this->report['bottlenecks'])->max('score_minutes') ?? 1); @endphp
        <div>
            <flux:heading size="sm">{{ __('Step bottlenecks') }}</flux:heading>
            <p class="mt-1 text-xs text-zinc-500">{{ __('Ranked by the greater of average completed duration and oldest open item. Exact sample sizes are shown below.') }}</p>
        </div>

        <div class="mt-5 space-y-4">
            @forelse ($this->report['bottlenecks'] as $step)
                <div class="grid items-center gap-2 sm:grid-cols-[minmax(10rem,16rem)_1fr_7rem]">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $step['step'] }}</p>
                        <p class="truncate text-xs text-zinc-500">{{ $step['workflow'] }}</p>
                    </div>
                    <div class="h-3 overflow-hidden rounded-sm bg-zinc-100 dark:bg-zinc-700">
                        <div class="h-full rounded-sm border-r border-blue-800 bg-blue-600" style="width: {{ max(1, ($step['score_minutes'] / $bottleneckMaximum) * 100) }}%"></div>
                    </div>
                    <p class="text-right text-sm font-medium tabular-nums">{{ $this->duration($step['score_minutes']) }}</p>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-zinc-500">{{ __('No workflow step activity is available for this period.') }}</p>
            @endforelse
        </div>

        @if ($this->report['bottlenecks'] !== [])
            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700">
                        <tr>
                            <th class="pb-2 font-medium">{{ __('Workflow / step') }}</th>
                            <th class="pb-2 text-right font-medium">{{ __('Avg completed') }}</th>
                            <th class="pb-2 text-right font-medium">{{ __('Samples') }}</th>
                            <th class="pb-2 text-right font-medium">{{ __('Open') }}</th>
                            <th class="pb-2 text-right font-medium">{{ __('Overdue') }}</th>
                            <th class="pb-2 text-right font-medium">{{ __('Oldest open') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->report['bottlenecks'] as $step)
                            <tr>
                                <td class="py-3"><span class="font-medium">{{ $step['step'] }}</span><span class="block text-xs text-zinc-500">{{ $step['workflow'] }}</span></td>
                                <td class="py-3 text-right tabular-nums">{{ $this->duration($step['average_minutes']) }}</td>
                                <td class="py-3 text-right tabular-nums">{{ $step['completed_samples'] }}</td>
                                <td class="py-3 text-right tabular-nums">{{ $step['open'] }}</td>
                                <td class="py-3 text-right tabular-nums">{{ $step['overdue'] }}</td>
                                <td class="py-3 text-right tabular-nums">{{ $this->duration($step['oldest_open_minutes']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </flux:card>

    <flux:card>
        @php($workloadMaximum = max(1, collect($this->report['workload_by_approver'])->max('active') ?? 1))
        <div>
            <flux:heading size="sm">{{ __('Workload by approver') }}</flux:heading>
            <p class="mt-1 text-xs text-zinc-500" role="note">{{ __('Active assignments are counted after current delegations are applied. The adjacent table contains the same values as the chart.') }}</p>
        </div>
        <div class="mt-5 grid gap-6 lg:grid-cols-2">
            <div class="space-y-3" role="img" aria-label="{{ __('Active and overdue workflow workload by approver') }}">
                @forelse ($this->report['workload_by_approver'] as $workload)
                    <div class="grid grid-cols-[minmax(8rem,12rem)_1fr_3rem] items-center gap-2">
                        <span class="truncate text-sm">{{ $workload['approver'] }}</span>
                        <div class="h-3 overflow-hidden rounded-sm bg-zinc-100 dark:bg-zinc-700"><div class="h-full rounded-sm bg-blue-600" style="width: {{ max(2, ($workload['active'] / $workloadMaximum) * 100) }}%"></div></div>
                        <span class="text-right text-sm font-medium tabular-nums">{{ $workload['active'] }}</span>
                    </div>
                @empty
                    <x-ui.empty-state :title="__('No active approver workload')" :description="__('Active assignments in this reporting period will appear here.')" icon="inbox" class="py-6" />
                @endforelse
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ __('Tabular alternative for approver workload chart') }}</caption>
                    <thead class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700"><tr><th class="pb-2">{{ __('Approver') }}</th><th class="pb-2 text-right">{{ __('Active') }}</th><th class="pb-2 text-right">{{ __('Overdue') }}</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($this->report['workload_by_approver'] as $workload)
                            <tr><td class="py-2">{{ $workload['approver'] }}</td><td class="py-2 text-right tabular-nums">{{ $workload['active'] }}</td><td class="py-2 text-right tabular-nums">{{ $workload['overdue'] }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="py-4 text-center text-zinc-500">{{ __('No active assignments') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </flux:card>

    <flux:card>
        <div class="flex items-center justify-between gap-3">
            <div>
                <flux:heading size="sm">{{ __('Longest-waiting active workflows') }}</flux:heading>
                <p class="mt-1 text-xs text-zinc-500">{{ __('Operational follow-up for the current step, limited to this period’s cohort.') }}</p>
            </div>
            <flux:badge color="zinc">{{ count($this->report['slowest_active']) }}</flux:badge>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700">
                    <tr>
                        <th class="pb-2 font-medium">{{ __('Submission') }}</th>
                        <th class="pb-2 font-medium">{{ __('Current step') }}</th>
                        <th class="pb-2 text-right font-medium">{{ __('Waiting') }}</th>
                        <th class="pb-2 text-right font-medium">{{ __('SLA') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->report['slowest_active'] as $workflow)
                        <tr>
                            <td class="py-3">
                                <flux:link :href="route('submissions.show', $workflow['submission_id'])" wire:navigate>{{ $workflow['title'] }}</flux:link>
                                <span class="block font-mono text-xs text-zinc-500">{{ $workflow['reference'] }}</span>
                            </td>
                            <td class="py-3">{{ $workflow['step'] }}</td>
                            <td class="py-3 text-right tabular-nums">{{ $this->duration($workflow['elapsed_minutes']) }}</td>
                            <td class="py-3 text-right"><flux:badge :color="$workflow['overdue'] ? 'red' : 'zinc'">{{ $workflow['overdue'] ? __('Overdue') : __('Within SLA') }}</flux:badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-zinc-500">{{ __('No active workflows in this period.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
