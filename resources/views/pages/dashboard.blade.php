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

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ __('Dashboard') }}</flux:heading>
            <flux:subheading>
                {{ $this->report['organization'] ? __('Organization-wide approval activity.') : __('Your submissions and approval work at a glance.') }}
            </flux:subheading>
        </div>

        @if ($this->report['can_submit'])
            <flux:button :href="route('submissions.create')" variant="primary" icon="plus" wire:navigate>
                {{ __('New submission') }}
            </flux:button>
        @endif
    </div>

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
                        <tr><td colspan="5" class="py-8 text-center text-zinc-500">{{ __('No submissions yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
