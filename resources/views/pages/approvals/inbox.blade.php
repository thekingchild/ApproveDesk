<?php

use App\Actions\Approvals\BatchApproveSubmissionsAction;
use App\Enums\SubmissionPriority;
use App\Enums\SubmissionType;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Department;
use App\Models\WorkflowInstance;
use App\Services\ApproverWorkbench;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Approvals')] class extends Component {
    use WithPagination;

    private const PER_PAGE = 25;

    #[Url]
    public string $view = 'needs_decision';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $deadline = '';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $requester = '';

    #[Url]
    public string $delegation = '';

    /** @var array<int, string> */
    public array $selected = [];

    #[Locked]
    public ?int $previewInstanceId = null;

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    #[Computed]
    public function allItems(): \Illuminate\Support\Collection
    {
        return collect(app(ApproverWorkbench::class)->itemsFor(Auth::user()));
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    #[Computed]
    public function items(): \Illuminate\Support\Collection
    {
        return $this->allItems
            ->filter(fn (array $item): bool => $this->matchesView($item))
            ->filter(fn (array $item): bool => $this->matchesFilters($item))
            ->values();
    }

    /** Render one page of the queue; a busy approver's full list was megabytes of HTML (RV-PERF-08). */
    #[Computed]
    public function pageItems(): \Illuminate\Pagination\LengthAwarePaginator
    {
        $page = max(1, $this->getPage());

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $this->items->forPage($page, self::PER_PAGE)->values(),
            $this->items->count(),
            self::PER_PAGE,
            $page,
        );
    }

    public function updated(string $property): void
    {
        if ($property !== 'selected' && $property !== 'previewInstanceId') {
            $this->resetPage();
        }
    }

    /** @return array<string, int> */
    #[Computed]
    public function viewCounts(): array
    {
        return collect(['needs_decision', 'due_soon', 'overdue', 'waiting', 'completed'])
            ->mapWithKeys(fn (string $view): array => [
                $view => $this->allItems->filter(fn (array $item): bool => $this->matchesView($item, $view))->count(),
            ])->all();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Department> */
    #[Computed]
    public function departments(): \Illuminate\Database\Eloquent\Collection
    {
        return Department::query()->orderBy('name')->get();
    }

    #[Computed]
    public function previewItem(): ?array
    {
        return $this->allItems->first(fn (array $item): bool => $item['instance']->id === $this->previewInstanceId);
    }

    #[Computed]
    public function batchEligible(): bool
    {
        $items = $this->allItems->whereIn('instance.id', array_map('intval', $this->selected));

        return $items->isNotEmpty()
            && $items->count() === count(array_unique($this->selected))
            && $items->every(fn (array $item): bool => $item['needs_me'])
            && $items->map(fn (array $item): string => $item['instance']->template_id.':'.$item['instance']->current_step_id)->unique()->count() === 1;
    }

    public function setView(string $view): void
    {
        abort_unless(in_array($view, ['needs_decision', 'due_soon', 'overdue', 'waiting', 'completed'], true), 404);

        $this->view = $view;
        $this->selected = [];
        $this->resetPage();
    }

    public function openPreview(int $instanceId): void
    {
        abort_unless($this->allItems->contains(fn (array $item): bool => $item['instance']->id === $instanceId), 404);
        $this->previewInstanceId = $instanceId;
    }

    public function closePreview(): void
    {
        $this->previewInstanceId = null;
    }

    public function approvePreview(): void
    {
        abort_if($this->previewInstanceId === null, 404);
        $this->approveIds([$this->previewInstanceId]);
        $this->previewInstanceId = null;
    }

    public function batchApprove(): void
    {
        if (! $this->batchEligible) {
            $this->addError('selected', 'Select eligible requests at the same workflow step.');

            return;
        }

        $this->approveIds($this->selected);
        $this->selected = [];
    }

    /** @param array<int, int|string> $ids */
    private function approveIds(array $ids): void
    {
        try {
            $count = app(BatchApproveSubmissionsAction::class)->execute($ids, Auth::user());
        } catch (WorkflowException $exception) {
            $this->addError('selected', $exception->userMessage());

            return;
        }

        unset($this->allItems, $this->items, $this->pageItems, $this->viewCounts, $this->previewItem, $this->batchEligible);
        Flux::toast(trans_choice('{1} Approval recorded.|[2,*] :count approvals recorded.', $count, ['count' => $count]), variant: 'success');
    }

    private function matchesView(array $item, ?string $view = null): bool
    {
        $view ??= $this->view;
        $instance = $item['instance'];

        return match ($view) {
            'needs_decision' => $item['needs_me'],
            'due_soon' => $item['needs_me'] && $item['deadline'] !== null
                && ! $instance->isOverdue() && $item['deadline']->lessThanOrEqualTo(now()->addHours(48)),
            'overdue' => $item['needs_me'] && $instance->isOverdue(),
            'waiting' => ! $item['needs_me'] && in_array($instance->status, [WorkflowStatus::Active, WorkflowStatus::OnHold], true),
            'completed' => in_array($instance->status, [WorkflowStatus::Completed, WorkflowStatus::Rejected, WorkflowStatus::Cancelled], true),
            default => false,
        };
    }

    private function matchesFilters(array $item): bool
    {
        $instance = $item['instance'];
        $submission = $instance->submission;
        $needle = mb_strtolower(trim($this->search));
        $haystack = mb_strtolower(implode(' ', [
            $submission->reference_number,
            $submission->title,
            $submission->submitter->name,
            json_encode($submission->metadata),
        ]));

        return ($needle === '' || str_contains($haystack, $needle))
            && ($this->status === '' || $instance->status->value === $this->status)
            && ($this->priority === '' || $submission->priority->value === $this->priority)
            && ($this->type === '' || $submission->type->value === $this->type)
            && ($this->department === '' || (string) $submission->department_id === $this->department)
            && ($this->requester === '' || str_contains(mb_strtolower($submission->submitter->name), mb_strtolower($this->requester)))
            && ($this->delegation === '' || $item['delegation'] === $this->delegation)
            && match ($this->deadline) {
                'overdue' => $instance->isOverdue(),
                'due_soon' => $item['deadline'] !== null && ! $instance->isOverdue() && $item['deadline']->lessThanOrEqualTo(now()->addHours(48)),
                'no_deadline' => $item['deadline'] === null,
                default => true,
            };
    }
} ?>

<div class="space-y-6">
    @if (session('status'))
        <flux:callout variant="secondary" icon="information-circle" data-test="queue-status">{{ session('status') }}</flux:callout>
    @endif
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Approvals')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <x-ui.page-header :title="__('Approval workbench')" :description="__('Review the highest-priority decisions first, preview context, and act without losing your place.')" help-article="approvals" />

    <nav aria-label="{{ __('Saved approval views') }}" class="flex gap-2 overflow-x-auto pb-1">
        @foreach ([
            'needs_decision' => __('Needs my decision'),
            'due_soon' => __('Due soon'),
            'overdue' => __('Overdue'),
            'waiting' => __('Waiting on others'),
            'completed' => __('Completed'),
        ] as $key => $label)
            <flux:button wire:click="setView('{{ $key }}')" :variant="$view === $key ? 'primary' : 'ghost'" class="shrink-0">
                {{ $label }}
                <flux:badge size="sm" color="zinc">{{ $this->viewCounts[$key] }}</flux:badge>
            </flux:button>
        @endforeach
    </nav>

    <flux:card>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :label="__('Search')" placeholder="{{ __('Reference, title, requester, or key field') }}" />
            <flux:select wire:model.live="status" :label="__('Status')">
                <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
                @foreach (WorkflowStatus::cases() as $option)<flux:select.option :value="$option->value">{{ str($option->value)->replace('_', ' ')->title() }}</flux:select.option>@endforeach
            </flux:select>
            <flux:select wire:model.live="deadline" :label="__('Deadline')">
                <flux:select.option value="">{{ __('Any deadline') }}</flux:select.option>
                <flux:select.option value="due_soon">{{ __('Due within 48 hours') }}</flux:select.option>
                <flux:select.option value="overdue">{{ __('Overdue') }}</flux:select.option>
                <flux:select.option value="no_deadline">{{ __('No SLA deadline') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="priority" :label="__('Priority')">
                <flux:select.option value="">{{ __('Any priority') }}</flux:select.option>
                @foreach (SubmissionPriority::cases() as $option)<flux:select.option :value="$option->value">{{ str($option->value)->title() }}</flux:select.option>@endforeach
            </flux:select>
            <flux:select wire:model.live="type" :label="__('Request type')">
                <flux:select.option value="">{{ __('Any request type') }}</flux:select.option>
                @foreach (SubmissionType::cases() as $option)<flux:select.option :value="$option->value">{{ str($option->value)->title() }}</flux:select.option>@endforeach
            </flux:select>
            <flux:select wire:model.live="department" :label="__('Department')">
                <flux:select.option value="">{{ __('Any department') }}</flux:select.option>
                @foreach ($this->departments as $option)<flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>@endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.300ms="requester" :label="__('Requester')" placeholder="{{ __('Requester name') }}" />
            <flux:select wire:model.live="delegation" :label="__('Delegation')">
                <flux:select.option value="">{{ __('Any assignment') }}</flux:select.option>
                <flux:select.option value="direct">{{ __('Assigned directly') }}</flux:select.option>
                <flux:select.option value="delegated_to_me">{{ __('Delegated to me') }}</flux:select.option>
                <flux:select.option value="delegated_by_me">{{ __('Delegated by me') }}</flux:select.option>
            </flux:select>
        </div>
    </flux:card>

    {{-- Selection is tracked in the browser; ticking a box no longer reloads the queue (RV-PERF-08). --}}
    <div x-cloak x-show="$wire.selected.length > 0" class="sticky top-3 z-20 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-blue-200 bg-white p-3 shadow-lg dark:border-blue-900 dark:bg-zinc-900" role="status" data-test="batch-bar">
        <span class="text-sm font-medium" x-text="$wire.selected.length === 1 ? @js(__('1 request selected')) : @js(__(':count requests selected')).replace(':count', $wire.selected.length)"></span>
        <flux:button variant="primary" wire:click="batchApprove" wire:confirm="{{ __('Approve all selected requests? Each approval is recorded separately in the audit trail.') }}">
            {{ __('Approve selected') }}
        </flux:button>
    </div>
    <flux:error name="selected" />

    <section aria-live="polite" aria-label="{{ __('Approval queue') }}" class="space-y-3">
        @forelse ($this->pageItems as $item)
            @php($instance = $item['instance'])
            @php($submission = $instance->submission)
            <article wire:key="approval-{{ $instance->id }}" class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start gap-3">
                    @if ($item['needs_me'])
                        <flux:checkbox wire:model="selected" value="{{ $instance->id }}" aria-label="{{ __('Select :title', ['title' => $submission->title]) }}" />
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <flux:link :href="route('submissions.show', $submission)" wire:navigate class="font-semibold">{{ $submission->title }}</flux:link>
                                <p class="mt-1 font-mono text-xs text-zinc-500">{{ $submission->reference_number }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($instance->isOverdue())
                                    <flux:badge color="red">{{ __('Overdue :time', ['time' => $item['deadline']->diffForHumans()]) }}</flux:badge>
                                @elseif ($item['deadline'])
                                    <flux:badge :color="$item['deadline']->lessThanOrEqualTo(now()->addHours(48)) ? 'amber' : 'zinc'">{{ __('Due :time', ['time' => $item['deadline']->diffForHumans()]) }}</flux:badge>
                                @else
                                    <flux:badge color="zinc">{{ __('No SLA') }}</flux:badge>
                                @endif
                                <flux:badge color="{{ ['urgent' => 'red', 'high' => 'amber', 'normal' => 'blue', 'low' => 'zinc'][$submission->priority->value] }}">{{ $submission->priority->value }}</flux:badge>
                            </div>
                        </div>

                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-6">
                            <div><dt class="text-xs text-zinc-500">{{ __('Requester') }}</dt><dd class="font-medium">{{ $submission->submitter->name }}</dd></div>
                            <div><dt class="text-xs text-zinc-500">{{ __('Key fields') }}</dt><dd class="font-medium">{{ ucfirst($submission->type->value) }} · {{ $submission->department?->name ?? __('No department') }}</dd></div>
                            <div><dt class="text-xs text-zinc-500">{{ __('Current step') }}</dt><dd class="font-medium">{{ $instance->currentStep?->name ?? '—' }}</dd></div>
                            <div><dt class="text-xs text-zinc-500">{{ __('Attachments') }}</dt><dd class="font-medium">{{ $submission->files_count }}</dd></div>
                            <div><dt class="text-xs text-zinc-500">{{ __('Assignment') }}</dt><dd class="font-medium">{{ str($item['delegation'])->replace('_', ' ') }}</dd></div>
                            <div><dt class="text-xs text-zinc-500">{{ __('Last activity') }}</dt><dd class="font-medium"><x-time :at="$item['last_activity']" format="relative" /></dd></div>
                        </dl>
                    </div>
                    <flux:button variant="ghost" wire:click="openPreview({{ $instance->id }})" aria-label="{{ __('Preview :title', ['title' => $submission->title]) }}">{{ __('Preview') }}</flux:button>
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="__('No approvals match this view')" :description="__('Try another saved view or clear one of the filters.')" icon="inbox" class="py-12" />
        @endforelse
        {{ $this->pageItems->links() }}
        @if ($view === 'completed' && $this->viewCounts['completed'] >= \App\Services\ApproverWorkbench::COMPLETED_LIMIT)
            <flux:text class="text-sm">{{ __('Showing your :count most recent decided requests. Older ones are in My submissions and reports.', ['count' => \App\Services\ApproverWorkbench::COMPLETED_LIMIT]) }}</flux:text>
        @endif
    </section>

    @if ($this->previewItem)
        @php($preview = $this->previewItem['instance'])
        @php($previewSubmission = $preview->submission)
        <div class="fixed inset-0 z-40 bg-black/30" wire:click="closePreview" aria-hidden="true"></div>
        <aside class="fixed inset-y-0 right-0 z-50 w-full max-w-xl overflow-y-auto border-l border-zinc-200 bg-white p-6 shadow-2xl dark:border-zinc-700 dark:bg-zinc-900" role="dialog" aria-modal="true" aria-labelledby="approval-preview-title">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading id="approval-preview-title" size="lg">{{ $previewSubmission->title }}</flux:heading>
                    <flux:subheading class="font-mono">{{ $previewSubmission->reference_number }}</flux:subheading>
                </div>
                <flux:button variant="ghost" wire:click="closePreview" aria-label="{{ __('Close preview') }}">{{ __('Close') }}</flux:button>
            </div>
            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-zinc-500">{{ __('Requester') }}</dt><dd class="font-medium">{{ $previewSubmission->submitter->name }}</dd></div>
                <div><dt class="text-xs text-zinc-500">{{ __('Current step') }}</dt><dd class="font-medium">{{ $preview->currentStep?->name }}</dd></div>
                <div><dt class="text-xs text-zinc-500">{{ __('Type') }}</dt><dd class="font-medium">{{ ucfirst($previewSubmission->type->value) }}</dd></div>
                <div><dt class="text-xs text-zinc-500">{{ __('Department') }}</dt><dd class="font-medium">{{ $previewSubmission->department?->name ?? '—' }}</dd></div>
            </dl>
            @if ($previewSubmission->body)
                <div class="prose prose-sm mt-6 max-w-none dark:prose-invert">{!! app(\App\Services\RichTextSanitizer::class)->sanitize($previewSubmission->body) !!}</div>
            @endif
            <p class="mt-6 text-sm text-zinc-500">{{ trans_choice('{0} No attachments|{1} 1 attachment|[2,*] :count attachments', $previewSubmission->files_count, ['count' => $previewSubmission->files_count]) }}</p>
            <div class="sticky bottom-0 mt-8 flex flex-wrap justify-end gap-3 border-t border-zinc-200 bg-white py-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:button :href="route('submissions.show', $previewSubmission)" wire:navigate variant="ghost">{{ __('Open full request') }}</flux:button>
                @if ($this->previewItem['needs_me'])
                    <flux:button variant="primary" wire:click="approvePreview" wire:confirm="{{ __('Approve this request?') }}">{{ __('Approve') }}</flux:button>
                @endif
            </div>
        </aside>
    @endif
</div>
