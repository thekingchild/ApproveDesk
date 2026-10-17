<?php

use App\Actions\RequestTypes\ArchiveRequestTypeAction;
use App\Actions\RequestTypes\AssignRequestTypeWorkflowAction;
use App\Actions\RequestTypes\CloneRequestTypeAction;
use App\Actions\RequestTypes\RestoreRequestTypeAction;
use App\Models\RequestType;
use App\Models\WorkflowTemplate;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Request types list and built-in forms. Building a form happens in the
 * admin.request-types.builder dialog (ADR 0006 split, RV-ARC-05).
 */
new #[Title('Request types')] class extends Component {
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('viewAny', RequestType::class);
    }

    #[Computed]
    public function requestTypes(): LengthAwarePaginator
    {
        return RequestType::query()
            ->with([
                'versions' => fn ($query) => $query->withCount('fields'),
                'workflowTemplate',
                'selectionRules.template',
            ])
            ->where('is_system', false)
            ->orderBy('name')
            ->paginate(15);
    }

    /** @return Collection<int, RequestType> */
    #[Computed]
    public function systemRequestTypes(): Collection
    {
        return RequestType::query()
            ->with('workflowTemplate')
            ->where('is_system', true)
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, WorkflowTemplate> */
    #[Computed]
    public function workflowTemplates(): Collection
    {
        return WorkflowTemplate::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function createType(): void
    {
        $this->authorize('create', RequestType::class);
        $this->dispatch('open-request-type-builder')->to('admin.request-types.builder');
    }

    public function editType(int $id): void
    {
        $this->authorize('update', RequestType::query()->findOrFail($id));
        $this->dispatch('open-request-type-builder', requestTypeId: $id)->to('admin.request-types.builder');
    }

    #[On('request-types-changed')]
    public function refreshTypes(): void
    {
        unset($this->requestTypes);
    }

    public function archive(int $id): void
    {
        $type = RequestType::findOrFail($id);
        $this->authorize('update', $type);
        app(ArchiveRequestTypeAction::class)->execute($type);
        unset($this->requestTypes);
        Flux::toast('Request type archived.', variant: 'success');
    }

    public function restore(int $id): void
    {
        $type = RequestType::findOrFail($id);
        $this->authorize('update', $type);
        app(RestoreRequestTypeAction::class)->execute($type);
        unset($this->requestTypes);
        Flux::toast('Request type restored.', variant: 'success');
    }

    public function cloneType(int $id): void
    {
        $source = RequestType::findOrFail($id);
        $this->authorize('create', RequestType::class);
        app(CloneRequestTypeAction::class)->execute($source, Auth::user());
        unset($this->requestTypes);
        Flux::toast('Request type cloned as a draft.', variant: 'success');
    }

    public function assignBuiltInWorkflow(int $requestTypeId, string $templateId = ''): void
    {
        $type = RequestType::query()->findOrFail($requestTypeId);
        $this->authorize('assignWorkflow', $type);
        app(AssignRequestTypeWorkflowAction::class)->execute(
            $type,
            $templateId !== '' ? (int) $templateId : null,
            Auth::user(),
        );
        unset($this->systemRequestTypes, $this->requestTypes);
        Flux::toast('Workflow assignment updated.', variant: 'success');
    }

    protected function workflowLabel(RequestType $type): string
    {
        $resolved = $type->resolvedWorkflowTemplate();

        if ($resolved === null) {
            return __('No default template');
        }

        if ($type->workflow_template_id === $resolved->id) {
            return $resolved->name;
        }

        return $resolved->name.' ('.__('default fallback').')';
    }
}; ?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Request types')]]" :back="route('dashboard')" back-label="Back to dashboard" />
    <x-ui.page-header title="Request types" description="Build versioned forms and assign the approval chain each form uses." help-article="request-types">
        <x-slot:actions><flux:button variant="primary" icon="plus" wire:click="createType" dusk="create-request-type">{{ __('Add request type') }}</flux:button></x-slot:actions>
    </x-ui.page-header>

    @if ($this->systemRequestTypes->isNotEmpty())
        <section class="space-y-3" data-test="built-in-request-types">
            <flux:heading size="sm">{{ __('Built-in forms') }}</flux:heading>
            @foreach ($this->systemRequestTypes as $type)
                <flux:card>
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p class="font-semibold text-zinc-950 dark:text-white">{{ $type->name }}</p>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $type->description ?: __('Fixed form used when a requester does not pick a custom type.') }}</p>
                            <p class="mt-2 text-sm text-zinc-500">{{ __('Next new submission uses') }}: {{ $this->workflowLabel($type) }}</p>
                        </div>
                        <div class="w-full max-w-xs">
                            <flux:select
                                :label="__('Workflow template')"
                                wire:change="assignBuiltInWorkflow({{ $type->id }}, $event.target.value)"
                                data-test="assign-built-in-workflow"
                            >
                                <flux:select.option value="">{{ __('Use default template') }}</flux:select.option>
                                @foreach ($this->workflowTemplates as $template)
                                    <flux:select.option :value="$template->id" :selected="$type->workflow_template_id === $template->id">{{ $template->name }}{{ $template->is_default ? ' ('.__('default').')' : '' }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </section>
    @endif

    <flux:table :paginate="$this->requestTypes">
        <flux:table.columns><flux:table.column>{{ __('Request type') }}</flux:table.column><flux:table.column>{{ __('Workflow') }}</flux:table.column><flux:table.column>{{ __('Versions') }}</flux:table.column><flux:table.column>{{ __('Status') }}</flux:table.column><flux:table.column /></flux:table.columns>
        <flux:table.rows>
            @forelse ($this->requestTypes as $type)
                <flux:table.row :key="$type->id">
                    <flux:table.cell><div class="font-medium">{{ $type->name }}</div><div class="text-xs text-zinc-500">{{ $type->description }}</div></flux:table.cell>
                    <flux:table.cell><span class="text-sm">{{ $this->workflowLabel($type) }}</span></flux:table.cell>
                    <flux:table.cell>{{ $type->versions->count() }}</flux:table.cell>
                    <flux:table.cell><flux:badge :color="$type->is_active ? 'lime' : 'zinc'">{{ $type->is_active ? __('Active') : __('Archived') }}</flux:badge></flux:table.cell>
                    <flux:table.cell><div class="flex flex-wrap justify-end gap-2"><flux:button size="sm" wire:click="editType({{ $type->id }})">{{ __('Edit') }}</flux:button><flux:button size="sm" wire:click="cloneType({{ $type->id }})">{{ __('Clone') }}</flux:button>@if ($type->is_active)<flux:button size="sm" variant="danger" wire:click="archive({{ $type->id }})" wire:confirm="Archive this request type?">{{ __('Archive') }}</flux:button>@else<flux:button size="sm" wire:click="restore({{ $type->id }})" wire:confirm="Restore this request type so people can submit it again?">{{ __('Restore') }}</flux:button>@endif</div></flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="5"><x-ui.empty-state title="No custom request types" description="Create the first versioned request form." /></flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <livewire:admin.request-types.builder wire:key="request-type-builder" />
</div>
