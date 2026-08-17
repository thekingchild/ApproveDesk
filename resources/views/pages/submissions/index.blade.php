<?php

use App\Models\Submission;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('My submissions')] class extends Component {
    use WithPagination;

    public string $statusFilter = '';

    #[\Livewire\Attributes\Computed]
    public function organizationView(): bool
    {
        return Auth::user()->can('submissions.view.all');
    }

    /** @var array<string, string> */
    protected function statuses(): array
    {
        return [
            '' => 'All statuses',
            'draft' => 'Draft',
            'in_review' => 'In review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'returned' => 'Returned',
            'withdrawn' => 'Withdrawn',
        ];
    }

    /** @var array<string, string> */
    protected function badgeColors(): array
    {
        return [
            'draft' => 'zinc',
            'pending' => 'blue',
            'in_review' => 'blue',
            'approved' => 'lime',
            'rejected' => 'red',
            'returned' => 'amber',
            'withdrawn' => 'zinc',
        ];
    }

    #[\Livewire\Attributes\Computed]
    public function submissions(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Submission::query()
            ->when(! $this->organizationView, fn ($query) => $query->where('submitter_id', Auth::id()))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->with(['submitter', 'workflowInstance'])
            ->latest()
            ->paginate(15);
    }
} ?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="lg">{{ $this->organizationView ? __('All submissions') : __('My submissions') }}</flux:heading>
            <flux:subheading>
                {{ $this->organizationView ? __('Documents, notes, and memos across the organization.') : __('Documents, notes, and memos you have submitted.') }}
            </flux:subheading>
        </div>

        @can('create', \App\Models\Submission::class)
            <flux:button :href="route('submissions.create')" variant="primary" wire:navigate>{{ __('New submission') }}</flux:button>
        @endcan
    </div>

    <div class="w-56">
        <flux:select wire:model.live="statusFilter" :label="__('Filter by status')">
            @foreach ($this->statuses() as $value => $label)
                <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->submissions">
        <flux:table.columns>
            <flux:table.column>{{ __('Reference') }}</flux:table.column>
            <flux:table.column>{{ __('Title') }}</flux:table.column>
            @if ($this->organizationView)<flux:table.column>{{ __('Submitter') }}</flux:table.column>@endif
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Priority') }}</flux:table.column>
            <flux:table.column>{{ __('Updated') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->submissions as $submission)
                <flux:table.row>
                    <flux:table.cell class="font-mono text-xs">{{ $submission->reference_number }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:link :href="route('submissions.show', $submission)" wire:navigate>{{ Str::limit($submission->title, 60) }}</flux:link>
                    </flux:table.cell>
                    @if ($this->organizationView)<flux:table.cell>{{ $submission->submitter->name }}</flux:table.cell>@endif
                    <flux:table.cell>{{ ucfirst($submission->type->value) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$this->badgeColors()[$submission->status->value] ?? 'zinc'">
                            {{ str_replace('_', ' ', $submission->status->value) }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ ucfirst($submission->priority->value) }}</flux:table.cell>
                    <flux:table.cell class="text-zinc-500">{{ $submission->updated_at?->diffForHumans() }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell :colspan="$this->organizationView ? 7 : 6" class="text-center text-zinc-500">{{ __('No submissions yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
