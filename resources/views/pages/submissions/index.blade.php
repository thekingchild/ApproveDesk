<?php

use App\Models\Submission;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('My submissions')] class extends Component {
    use WithPagination;

    public string $statusFilter = '';

    public string $search = '';

    public string $typeFilter = '';

    public string $submitterFilter = '';

    public string $departmentFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    #[\Livewire\Attributes\Computed]
    public function organizationView(): bool
    {
        return Auth::user()->can('submissions.view.all');
    }

    #[\Livewire\Attributes\Computed]
    public function submitters(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()->whereHas('submissions')->orderBy('name')->get();
    }

    #[\Livewire\Attributes\Computed]
    public function departments(): \Illuminate\Database\Eloquent\Collection
    {
        return Department::query()->orderBy('name')->get();
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'search', 'statusFilter', 'typeFilter', 'submitterFilter',
            'departmentFilter', 'dateFrom', 'dateTo',
        ], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'statusFilter', 'typeFilter', 'submitterFilter',
            'departmentFilter', 'dateFrom', 'dateTo',
        ]);
        $this->resetPage();
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
            'awaiting_information' => 'Awaiting information',
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
            'awaiting_information' => 'amber',
            'withdrawn' => 'zinc',
        ];
    }

    #[\Livewire\Attributes\Computed]
    public function submissions(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Submission::query()
            ->when(! $this->organizationView, fn ($query) => $query->where('submitter_id', Auth::id()))
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $search): void {
                    $search->where('reference_number', 'like', '%'.$this->search.'%')
                        ->orWhere('title', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->typeFilter !== '', fn ($query) => $query->where('type', $this->typeFilter))
            ->when($this->organizationView && ctype_digit($this->submitterFilter), fn ($query) => $query->where('submitter_id', (int) $this->submitterFilter))
            ->when($this->organizationView && ctype_digit($this->departmentFilter), fn ($query) => $query->where('department_id', (int) $this->departmentFilter))
            ->when($this->validDate($this->dateFrom), fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->validDate($this->dateTo), fn ($query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->with(['submitter', 'workflowInstance.currentStep'])
            ->latest()
            ->paginate(15);
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
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

    <flux:card class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Reference or title')" />
        <flux:select wire:model.live="statusFilter" :label="__('Status')">
            @foreach ($this->statuses() as $value => $label)
                <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="typeFilter" :label="__('Type')">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach (\App\Enums\SubmissionType::cases() as $type)
                <flux:select.option :value="$type->value">{{ str($type->value)->title() }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($this->organizationView)
            <flux:select wire:model.live="submitterFilter" :label="__('Submitter')">
                <flux:select.option value="">{{ __('All submitters') }}</flux:select.option>
                @foreach ($this->submitters as $submitter)<flux:select.option :value="$submitter->id">{{ $submitter->name }}</flux:select.option>@endforeach
            </flux:select>
            <flux:select wire:model.live="departmentFilter" :label="__('Department')">
                <flux:select.option value="">{{ __('All departments') }}</flux:select.option>
                @foreach ($this->departments as $department)<flux:select.option :value="$department->id">{{ $department->name }}</flux:select.option>@endforeach
            </flux:select>
        @endif
        <flux:input wire:model.live="dateFrom" type="date" :label="__('Created from')" />
        <flux:input wire:model.live="dateTo" type="date" :label="__('Created to')" />
        <div class="flex items-end">
            <flux:button type="button" variant="ghost" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        </div>
    </flux:card>

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
                        @if ($submission->workflowInstance?->isOverdue())
                            <flux:badge color="red" class="ms-1">{{ __('Overdue') }}</flux:badge>
                        @endif
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
