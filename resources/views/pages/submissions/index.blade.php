<?php

use App\Models\Submission;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('My submissions')] class extends Component {
    use WithPagination;

    #[Url]
    public string $view = 'all';

    public string $statusFilter = '';

    public string $search = '';

    public string $typeFilter = '';

    public string $submitterFilter = '';

    public string $departmentFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @return array<string, array{label: string, statuses: list<string>}> */
    protected function savedViews(): array
    {
        return [
            'all' => ['label' => 'All requests', 'statuses' => []],
            'drafts' => ['label' => 'Drafts', 'statuses' => ['draft']],
            'in_progress' => ['label' => 'In progress', 'statuses' => ['pending', 'in_review']],
            'needs_response' => ['label' => 'Needs my response', 'statuses' => ['returned', 'awaiting_information']],
            'completed' => ['label' => 'Completed', 'statuses' => ['approved', 'rejected']],
            'withdrawn' => ['label' => 'Withdrawn', 'statuses' => ['withdrawn']],
        ];
    }

    /** @return array<string, int> */
    #[Computed]
    public function savedViewCounts(): array
    {
        if ($this->organizationView) {
            return [];
        }

        $counts = Submission::query()
            ->where('submitter_id', Auth::id())
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect($this->savedViews())->mapWithKeys(function (array $savedView, string $key) use ($counts): array {
            $count = $savedView['statuses'] === []
                ? (int) $counts->sum()
                : collect($savedView['statuses'])->sum(fn (string $status): int => (int) ($counts[$status] ?? 0));

            return [$key => $count];
        })->all();
    }

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
            'departmentFilter', 'dateFrom', 'dateTo', 'view',
        ], true)) {
            $this->resetPage();
        }
    }

    public function setView(string $view): void
    {
        abort_unless(array_key_exists($view, $this->savedViews()), 404);

        $this->view = $view;
        $this->statusFilter = '';
        $this->resetPage();
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
            ->when(! $this->organizationView && $this->view !== 'all', function (Builder $query): void {
                $statuses = $this->savedViews()[$this->view]['statuses'] ?? [];
                $query->whereIn('status', $statuses);
            })
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
            ->with(['submitter', 'requestTypeVersion.requestType', 'workflowInstance.currentStep'])
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
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => $this->organizationView ? __('All submissions') : __('My submissions')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <x-ui.page-header
        :title="$this->organizationView ? __('All submissions') : __('My submissions')"
        :description="$this->organizationView ? __('Documents, notes, and memos across the organization.') : __('Documents, notes, and memos you have submitted.')"
    >
        @can('create', \App\Models\Submission::class)
            <x-slot:actions>
                <flux:button :href="route('submissions.choose-type')" variant="primary" icon="plus" wire:navigate>{{ __('New submission') }}</flux:button>
            </x-slot:actions>
        @endcan
    </x-ui.page-header>

    @if (! $this->organizationView)
        <nav aria-label="{{ __('Saved request views') }}" class="flex gap-2 overflow-x-auto pb-1">
            @foreach ($this->savedViews() as $key => $savedView)
                <flux:button wire:click="setView('{{ $key }}')" :variant="$view === $key ? 'primary' : 'ghost'" class="shrink-0">
                    {{ __($savedView['label']) }}
                    <flux:badge size="sm" color="zinc">{{ $this->savedViewCounts[$key] }}</flux:badge>
                </flux:button>
            @endforeach
        </nav>
    @endif

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

    <flux:table :paginate="$this->submissions" container:class="ad-responsive-table">
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
                    <flux:table.cell :data-label="__('Reference')" class="font-mono text-xs">{{ $submission->reference_number }}</flux:table.cell>
                    <flux:table.cell :data-label="__('Title')">
                        @can('update', $submission)
                            <flux:link :href="$submission->requestTypeVersion->requestType->is_system ? route('submissions.edit', $submission) : route('submissions.custom.edit', $submission)" wire:navigate>{{ Str::limit($submission->title, 60) }}</flux:link>
                        @else
                            <flux:link :href="route('submissions.show', $submission)" wire:navigate>{{ Str::limit($submission->title, 60) }}</flux:link>
                        @endcan
                    </flux:table.cell>
                    @if ($this->organizationView)<flux:table.cell :data-label="__('Submitter')">{{ $submission->submitter->name }}</flux:table.cell>@endif
                    <flux:table.cell :data-label="__('Type')">{{ ucfirst($submission->type->value) }}</flux:table.cell>
                    <flux:table.cell :data-label="__('Status')">
                        <flux:badge :color="$this->badgeColors()[$submission->status->value] ?? 'zinc'">
                            {{ str_replace('_', ' ', $submission->status->value) }}
                        </flux:badge>
                        @if ($submission->workflowInstance?->isOverdue())
                            <flux:badge color="red" class="ms-1">{{ __('Overdue') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell :data-label="__('Priority')">{{ ucfirst($submission->priority->value) }}</flux:table.cell>
                    <flux:table.cell :data-label="__('Updated')" class="text-zinc-600 dark:text-zinc-300">{{ $submission->updated_at?->diffForHumans() }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell :colspan="$this->organizationView ? 7 : 6" data-empty-state>
                        @php
                            $emptyStates = [
                                'drafts' => [__('No drafts to continue'), __('Start a request now and it will remain here until you submit it.')],
                                'in_progress' => [__('Nothing is awaiting approval'), __('Submitted requests will appear here while their approval workflow is running.')],
                                'needs_response' => [__('No responses are needed'), __('Returned requests and information requests that need your attention will appear here.')],
                                'completed' => [__('No completed requests yet'), __('Approved and rejected requests will remain here for easy reference.')],
                                'withdrawn' => [__('No withdrawn requests'), __('Requests you withdraw before completion will appear here.')],
                                'all' => [__('No requests yet'), __('Create your first request to start an approval workflow.')],
                            ];
                            [$emptyTitle, $emptyDescription] = $this->organizationView
                                ? [__('No submissions found'), __('Try clearing the filters or wait for a requester to submit new work.')]
                                : ($emptyStates[$view] ?? $emptyStates['all']);
                        @endphp
                        <x-ui.empty-state
                            :title="$emptyTitle"
                            :description="$emptyDescription"
                            icon="document-magnifying-glass"
                        >
                            @can('create', \App\Models\Submission::class)
                                <x-slot:actions>
                                    <flux:button :href="route('submissions.choose-type')" variant="primary" wire:navigate>{{ __('New request') }}</flux:button>
                                    @if ($view !== 'all')
                                        <flux:button wire:click="setView('all')" variant="ghost">{{ __('View all requests') }}</flux:button>
                                    @endif
                                </x-slot:actions>
                            @endcan
                        </x-ui.empty-state>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
