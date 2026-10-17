<?php

use App\Actions\WorkflowTemplates\CloneWorkflowTemplateAction;
use App\Models\WorkflowTemplate;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Workflow templates list. Adding and editing happen in the
 * admin.workflow-templates.editor dialog (ADR 0006 split, RV-ARC-06).
 */
new #[Title('Workflow templates')] class extends Component {
    use WithPagination;

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', WorkflowTemplate::class);
    }

    #[Computed]
    public function templates(): LengthAwarePaginator
    {
        return WorkflowTemplate::query()
            ->with([
                'creator',
                'requestTypes',
                'publishedVersion' => fn ($query) => $query->withCount('steps'),
                'draftVersion',
            ])
            ->withCount(['instances', 'versions'])
            ->when($this->search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(15);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function cloneTemplate(int $templateId): void
    {
        $source = WorkflowTemplate::query()
            ->with(['publishedVersion.steps', 'draftVersion.steps'])
            ->findOrFail($templateId);
        $this->authorize('create', WorkflowTemplate::class);
        app(CloneWorkflowTemplateAction::class)->execute($source, Auth::user());
        unset($this->templates);
        Flux::toast('Workflow template cloned.', variant: 'success');
    }

    public function createTemplate(): void
    {
        $this->authorize('create', WorkflowTemplate::class);
        $this->dispatch('open-workflow-template-form')->to('admin.workflow-templates.editor');
    }

    public function editTemplate(int $templateId): void
    {
        $this->authorize('update', WorkflowTemplate::query()->findOrFail($templateId));
        $this->dispatch('open-workflow-template-form', templateId: $templateId)->to('admin.workflow-templates.editor');
    }

    #[On('workflow-templates-changed')]
    public function refreshTemplates(): void
    {
        unset($this->templates);
    }
} ?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Workflow templates')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex items-center gap-1">
                <flux:heading size="lg">{{ __('Workflow templates') }}</flux:heading>
                <x-ui.help-link article="workflow-templates" />
            </div>
            <flux:subheading>{{ __('Name as many approval chains as you need. Assign each one from a request type. The default is the fallback.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="createTemplate" data-test="create-template">{{ __('Add template') }}</flux:button>
    </div>

    <div class="max-w-sm">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search templates…')" />
    </div>

    <flux:table :paginate="$this->templates">
        <flux:table.columns>
            <flux:table.column>{{ __('Template') }}</flux:table.column>
            <flux:table.column>{{ __('Assigned request types') }}</flux:table.column>
            <flux:table.column>{{ __('Steps') }}</flux:table.column>
            <flux:table.column>{{ __('Usage') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->templates as $template)
                <flux:table.row :key="$template->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $template->name }}</div>
                        <div class="text-xs text-zinc-500">{{ __('Created by') }} {{ $template->creator->name }}</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap items-center gap-1">
                            @if ($template->is_default)
                                <flux:badge color="indigo">{{ __('Default fallback') }}</flux:badge>
                            @endif
                            @forelse ($template->requestTypes as $requestType)
                                <flux:badge color="zinc">{{ $requestType->name }}</flux:badge>
                            @empty
                                @unless ($template->is_default)
                                    <span class="text-sm text-zinc-500">{{ __('Not assigned') }}</span>
                                @endunless
                            @endforelse
                        </div>
                    </flux:table.cell>
                    <flux:table.cell><flux:badge color="zinc">{{ $template->publishedVersion?->steps_count ?? 0 }}</flux:badge></flux:table.cell>
                    <flux:table.cell><flux:badge :color="$template->instances_count ? 'amber' : 'zinc'">{{ $template->instances_count }}</flux:badge></flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            <flux:badge :color="$template->is_active ? 'lime' : 'zinc'">{{ $template->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                            @if ($template->publishedVersion)
                                <flux:badge color="indigo">{{ __('Published v:version', ['version' => $template->publishedVersion->version]) }}</flux:badge>
                            @endif
                            @if ($template->draftVersion)
                                <flux:badge color="amber">{{ __('Draft v:version', ['version' => $template->draftVersion->version]) }}</flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="text-right">
                        <div class="flex flex-wrap justify-end gap-1">
                            <flux:button size="xs" variant="ghost" :href="route('admin.workflow-templates.builder', $template)" wire:navigate data-test="open-builder">{{ __('Builder') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="cloneTemplate({{ $template->id }})" data-test="clone-template">{{ __('Clone') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="editTemplate({{ $template->id }})">{{ __('Edit') }}</flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('No workflow templates found.') }}</flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <livewire:admin.workflow-templates.editor wire:key="workflow-template-editor" />
</div>
