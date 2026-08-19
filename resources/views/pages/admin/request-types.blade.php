<?php

use App\Actions\RequestTypes\ArchiveRequestTypeAction;
use App\Actions\RequestTypes\AssignRequestTypeWorkflowAction;
use App\Actions\RequestTypes\CloneRequestTypeAction;
use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\RequestTypes\SaveRequestTypeDraftAction;
use App\Enums\RequestFieldType;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\WorkflowTemplate;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Request types')] class extends Component {
    use WithPagination;

    public ?int $editingTypeId = null;
    public string $name = '';
    public string $description = '';
    public string $workflowTemplateId = '';

    /** @var array<int, array<string, mixed>> */
    public array $fields = [];

    public function mount(): void
    {
        $this->authorize('viewAny', RequestType::class);
    }

    #[Computed]
    public function requestTypes(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return RequestType::query()
            ->with([
                'versions' => fn ($query) => $query->withCount('fields'),
                'workflowTemplate',
            ])
            ->where('is_system', false)
            ->orderBy('name')
            ->paginate(15);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, RequestType> */
    #[Computed]
    public function systemRequestTypes(): \Illuminate\Database\Eloquent\Collection
    {
        return RequestType::query()
            ->with('workflowTemplate')
            ->where('is_system', true)
            ->orderBy('name')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, WorkflowTemplate> */
    #[Computed]
    public function workflowTemplates(): \Illuminate\Database\Eloquent\Collection
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
        $this->resetBuilder();
        $this->addField();
        $this->modal('request-type-builder')->show();
    }

    public function editType(int $id): void
    {
        $type = RequestType::query()->with(['versions.fields'])->findOrFail($id);
        $this->authorize('update', $type);
        $version = $type->versions->first();
        $this->editingTypeId = $type->id;
        $this->name = $type->name;
        $this->description = $type->description ?? '';
        $this->workflowTemplateId = $type->workflow_template_id ? (string) $type->workflow_template_id : '';
        $this->fields = $version?->fields->map(fn ($field): array => $this->fieldForEditor($field))->values()->all() ?? [];
        $this->modal('request-type-builder')->show();
    }

    public function addField(): void
    {
        $this->fields[] = $this->blankField();
    }

    public function duplicateField(int $index): void
    {
        if (! isset($this->fields[$index])) {
            return;
        }

        $copy = $this->fields[$index];
        $copy['label'] .= ' copy';
        $copy['key'] .= '_copy';
        array_splice($this->fields, $index + 1, 0, [$copy]);
    }

    public function removeField(int $index): void
    {
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    public function moveField(int $index, int $direction): void
    {
        $destination = $index + $direction;

        if (! isset($this->fields[$index], $this->fields[$destination])) {
            return;
        }

        [$this->fields[$index], $this->fields[$destination]] = [$this->fields[$destination], $this->fields[$index]];
    }

    public function sortField(int $item, int $position): void
    {
        if (! isset($this->fields[$item])) {
            return;
        }

        $field = $this->fields[$item];
        array_splice($this->fields, $item, 1);
        array_splice($this->fields, $position, 0, [$field]);
    }

    public function updatedFields(mixed $value, ?string $path = null): void
    {
        if ($path !== null && str_ends_with($path, '.label')) {
            $index = (int) str($path)->before('.')->toString();

            if (($this->fields[$index]['key'] ?? '') === '') {
                $this->fields[$index]['key'] = Str::snake((string) $value);
            }
        }
    }

    public function importOptions(int $index): void
    {
        $csv = (string) ($this->fields[$index]['optionsCsv'] ?? '');
        $options = collect(preg_split('/[\r\n,]+/', $csv) ?: [])
            ->map(fn (string $option): string => trim($option))
            ->filter()->unique()->values()->all();
        $this->fields[$index]['options'] = implode("\n", $options);
    }

    public function exportOptions(int $index): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $options = $this->options($this->fields[$index]['options'] ?? '');

        return response()->streamDownload(function () use ($options): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['option']);
            foreach ($options as $option) {
                fputcsv($handle, [$option]);
            }
            fclose($handle);
        }, 'request-field-options.csv', ['Content-Type' => 'text/csv']);
    }

    public function save(bool $publish = false): void
    {
        $this->authorize($this->editingTypeId ? 'update' : 'create', $this->editingTypeId ? RequestType::findOrFail($this->editingTypeId) : RequestType::class);
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'workflowTemplateId' => $this->workflowTemplateId === ''
                ? ['nullable']
                : ['integer', Rule::exists(WorkflowTemplate::class, 'id')],
            'fields' => ['required', 'array', 'min:1', 'max:100'],
            'fields.*.label' => ['required', 'string', 'max:150'],
            'fields.*.key' => ['required', 'alpha_dash:ascii', 'max:100'],
            'fields.*.type' => ['required', \Illuminate\Validation\Rule::enum(RequestFieldType::class)],
        ]);

        $type = $this->editingTypeId ? RequestType::findOrFail($this->editingTypeId) : null;
        $version = app(SaveRequestTypeDraftAction::class)->execute(
            $type,
            $this->name,
            $this->description ?: null,
            collect($this->fields)->map(fn (array $field): array => $this->fieldForStorage($field))->all(),
            auth()->user(),
        );

        app(AssignRequestTypeWorkflowAction::class)->execute(
            $version->requestType,
            $this->workflowTemplateId !== '' ? (int) $this->workflowTemplateId : null,
            auth()->user(),
        );

        if ($publish) {
            app(PublishRequestTypeVersionAction::class)->execute($version);
        }

        $this->modal('request-type-builder')->close();
        $this->resetBuilder();
        unset($this->requestTypes);
        Flux::toast($publish ? 'Request type published.' : 'Draft saved.', variant: 'success');
    }

    public function archive(int $id): void
    {
        $type = RequestType::findOrFail($id);
        $this->authorize('update', $type);
        app(ArchiveRequestTypeAction::class)->execute($type);
        unset($this->requestTypes);
        Flux::toast('Request type archived.', variant: 'success');
    }

    public function cloneType(int $id): void
    {
        $source = RequestType::findOrFail($id);
        $this->authorize('create', RequestType::class);
        app(CloneRequestTypeAction::class)->execute($source, auth()->user());
        unset($this->requestTypes);
        Flux::toast('Request type cloned as a draft.', variant: 'success');
    }

    /** @return array<string, mixed> */
    private function blankField(): array
    {
        return [
            'label' => '', 'key' => '', 'type' => RequestFieldType::ShortText->value, 'is_required' => false,
            'is_searchable' => false, 'is_filterable' => false, 'is_reportable' => false,
            'is_sensitive' => false, 'is_approver_summary' => false,
            'section' => '', 'instructions' => '', 'placeholder' => '', 'help' => '', 'default' => '',
            'maxLength' => '', 'min' => '', 'max' => '', 'options' => '', 'optionsCsv' => '', 'allowedTypes' => '',
            'visibilityField' => '', 'visibilityOperator' => 'equals', 'visibilityValue' => '',
            'requiredField' => '', 'requiredOperator' => 'equals', 'requiredValue' => '', 'calculationFields' => '',
        ];
    }

    private function fieldForEditor($field): array
    {
        $config = $field->configuration ?? [];

        return [...$this->blankField(),
            'label' => $field->label, 'key' => $field->key, 'type' => $field->type->value, 'is_required' => $field->is_required,
            'is_searchable' => $field->is_searchable, 'is_filterable' => $field->is_filterable,
            'is_reportable' => $field->is_reportable, 'is_sensitive' => $field->is_sensitive,
            'is_approver_summary' => $field->is_approver_summary,
            'section' => $config['section'] ?? '', 'instructions' => $config['instructions'] ?? '',
            'placeholder' => $config['placeholder'] ?? '', 'help' => $config['help'] ?? '', 'default' => $config['default'] ?? '',
            'maxLength' => $config['max_length'] ?? '', 'min' => $config['min'] ?? '', 'max' => $config['max'] ?? '',
            'options' => implode("\n", $config['options'] ?? []), 'allowedTypes' => implode(',', $config['allowed_types'] ?? []),
            'visibilityField' => data_get($config, 'visibility.field', ''), 'visibilityOperator' => data_get($config, 'visibility.operator', 'equals'),
            'visibilityValue' => data_get($config, 'visibility.value', ''), 'requiredField' => data_get($config, 'required_when.field', ''),
            'requiredOperator' => data_get($config, 'required_when.operator', 'equals'), 'requiredValue' => data_get($config, 'required_when.value', ''),
            'calculationFields' => implode(',', data_get($config, 'calculation.fields', [])),
        ];
    }

    /** @return array<string, mixed> */
    private function fieldForStorage(array $field): array
    {
        $config = array_filter([
            'section' => $field['section'] ?: null, 'instructions' => $field['instructions'] ?: null,
            'placeholder' => $field['placeholder'] ?: null, 'help' => $field['help'] ?: null,
            'default' => $field['default'] !== '' ? $field['default'] : null,
            'max_length' => $field['maxLength'] !== '' ? (int) $field['maxLength'] : null,
            'min' => $field['min'] !== '' ? (float) $field['min'] : null, 'max' => $field['max'] !== '' ? (float) $field['max'] : null,
            'options' => $this->options($field['options']),
            'allowed_types' => $this->options($field['allowedTypes']),
            'visibility' => $field['visibilityField'] ? ['field' => $field['visibilityField'], 'operator' => $field['visibilityOperator'], 'value' => $field['visibilityValue']] : null,
            'required_when' => $field['requiredField'] ? ['field' => $field['requiredField'], 'operator' => $field['requiredOperator'], 'value' => $field['requiredValue']] : null,
            'calculation' => $field['type'] === RequestFieldType::Calculated->value ? ['operation' => 'sum', 'fields' => $this->options($field['calculationFields'])] : null,
        ], fn ($value): bool => $value !== null && $value !== []);

        return [
            'key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'],
            'is_required' => $field['is_required'], 'is_searchable' => $field['is_searchable'] ?? false,
            'is_filterable' => $field['is_filterable'] ?? false, 'is_reportable' => $field['is_reportable'] ?? false,
            'is_sensitive' => $field['is_sensitive'] ?? false, 'is_approver_summary' => $field['is_approver_summary'] ?? false,
            'configuration' => $config ?: null,
        ];
    }

    /** @return list<string> */
    private function options(string $options): array
    {
        return collect(preg_split('/[\r\n,]+/', $options) ?: [])->map(fn ($item) => trim($item))->filter()->unique()->values()->all();
    }

    public function assignBuiltInWorkflow(int $requestTypeId, string $templateId = ''): void
    {
        $type = RequestType::query()->findOrFail($requestTypeId);
        app(AssignRequestTypeWorkflowAction::class)->execute(
            $type,
            $templateId !== '' ? (int) $templateId : null,
            auth()->user(),
        );
        unset($this->systemRequestTypes, $this->requestTypes);
        Flux::toast('Workflow assignment updated.', variant: 'success');
    }

    public function workflowLabel(RequestType $type): string
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

    private function resetBuilder(): void
    {
        $this->resetValidation();
        $this->reset(['editingTypeId', 'name', 'description', 'workflowTemplateId', 'fields']);
    }
};
?>

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
                    <flux:table.cell><div class="flex flex-wrap justify-end gap-2"><flux:button size="sm" wire:click="editType({{ $type->id }})">{{ __('Edit') }}</flux:button><flux:button size="sm" wire:click="cloneType({{ $type->id }})">{{ __('Clone') }}</flux:button><flux:button size="sm" variant="danger" wire:click="archive({{ $type->id }})" wire:confirm="Archive this request type?">{{ __('Archive') }}</flux:button></div></flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="5"><x-ui.empty-state title="No custom request types" description="Create the first versioned request form." /></flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="request-type-builder" class="w-full max-w-6xl">
        <div class="space-y-5">
            <div><flux:heading size="lg">{{ $editingTypeId ? __('Edit request type') : __('New request type') }}</flux:heading><flux:subheading>{{ __('Published versions stay immutable; later edits create a new draft.') }}</flux:subheading></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" label="Name" required dusk="request-type-name" />
                <flux:input wire:model="description" label="Description" />
                <div class="sm:col-span-2">
                    <flux:select wire:model="workflowTemplateId" :label="__('Workflow template')" data-test="request-type-workflow">
                        <flux:select.option value="">{{ __('Use default template') }}</flux:select.option>
                        @foreach ($this->workflowTemplates as $template)
                            <flux:select.option :value="$template->id">{{ $template->name }}{{ $template->is_default ? ' ('.__('default').')' : '' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('New submissions of this form start this approval chain. In-flight requests keep the chain they already have.') }}</p>
                    <flux:error name="workflowTemplateId" />
                </div>
            </div>
            <div class="flex items-center justify-between"><flux:heading size="md">{{ __('Fields') }}</flux:heading><flux:button wire:click="addField" icon="plus" dusk="add-field">{{ __('Add field') }}</flux:button></div>
            <div class="space-y-4" wire:sort="sortField">
                @foreach ($fields as $index => $field)
                    <section wire:key="field-{{ $index }}" wire:sort:item="{{ $index }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" dusk="builder-field-{{ $index }}">
                        <div class="mb-3 flex items-center justify-between"><div class="font-semibold" wire:sort:handle>⋮⋮ {{ __('Field') }} {{ $index + 1 }}</div><div class="flex gap-1"><flux:button size="sm" wire:click="moveField({{ $index }}, -1)" aria-label="Move field up">↑</flux:button><flux:button size="sm" wire:click="moveField({{ $index }}, 1)" aria-label="Move field down">↓</flux:button><flux:button size="sm" wire:click="duplicateField({{ $index }})">{{ __('Duplicate') }}</flux:button><flux:button size="sm" variant="danger" wire:click="removeField({{ $index }})">{{ __('Delete') }}</flux:button></div></div>
                        <div class="grid gap-3 md:grid-cols-3">
                            <flux:input wire:model.live="fields.{{ $index }}.label" label="Label" required dusk="field-label-{{ $index }}" /><flux:input wire:model="fields.{{ $index }}.key" label="Key" required dusk="field-key-{{ $index }}" />
                            <flux:select wire:model.live="fields.{{ $index }}.type" label="Type" dusk="field-type-{{ $index }}">@foreach (RequestFieldType::cases() as $case)<flux:select.option :value="$case->value">{{ Str::headline($case->value) }}</flux:select.option>@endforeach</flux:select>
                            <flux:input wire:model="fields.{{ $index }}.section" label="Section" /><flux:input wire:model="fields.{{ $index }}.placeholder" label="Placeholder" /><flux:input wire:model="fields.{{ $index }}.help" label="Help text" />
                            <flux:input wire:model="fields.{{ $index }}.instructions" label="Instructions" /><flux:input wire:model="fields.{{ $index }}.default" label="Default value" /><flux:checkbox wire:model="fields.{{ $index }}.is_required" label="Always required" dusk="field-required-{{ $index }}" />
                            <flux:input wire:model="fields.{{ $index }}.maxLength" type="number" label="Maximum length" /><flux:input wire:model="fields.{{ $index }}.min" type="number" label="Minimum" /><flux:input wire:model="fields.{{ $index }}.max" type="number" label="Maximum" />
                        </div>
                        <fieldset class="mt-3 grid gap-2 rounded-lg bg-zinc-50 p-3 sm:grid-cols-2 lg:grid-cols-5 dark:bg-zinc-800/50">
                            <legend class="px-1 text-sm font-medium">{{ __('Discovery and privacy') }}</legend>
                            <flux:checkbox wire:model="fields.{{ $index }}.is_searchable" label="Searchable" />
                            <flux:checkbox wire:model="fields.{{ $index }}.is_filterable" label="Filterable" />
                            <flux:checkbox wire:model="fields.{{ $index }}.is_reportable" label="Reportable" />
                            <flux:checkbox wire:model="fields.{{ $index }}.is_sensitive" label="Sensitive" />
                            <flux:checkbox wire:model="fields.{{ $index }}.is_approver_summary" label="Approver summary" />
                        </fieldset>
                        @if (in_array($field['type'], ['select', 'multi_select', 'radio']))
                            <div class="mt-3 grid gap-3 md:grid-cols-2"><flux:textarea wire:model="fields.{{ $index }}.options" label="Options (one per line)" /><div><flux:textarea wire:model="fields.{{ $index }}.optionsCsv" label="CSV options" /><div class="mt-2 flex gap-2"><flux:button size="sm" wire:click="importOptions({{ $index }})">{{ __('Import CSV') }}</flux:button><flux:button size="sm" wire:click="exportOptions({{ $index }})">{{ __('Export CSV') }}</flux:button></div></div></div>
                        @endif
                        @if ($field['type'] === 'file')<div class="mt-3"><flux:input wire:model="fields.{{ $index }}.allowedTypes" label="Allowed extensions (comma separated)" /></div>@endif
                        @if ($field['type'] === 'calculated')<div class="mt-3"><flux:input wire:model="fields.{{ $index }}.calculationFields" label="Fields to sum (comma separated keys)" /></div>@endif
                        <details class="mt-3"><summary class="cursor-pointer font-medium">{{ __('Conditional rules') }}</summary><div class="mt-3 grid gap-3 md:grid-cols-3"><flux:input wire:model="fields.{{ $index }}.visibilityField" label="Show when field" /><flux:select wire:model="fields.{{ $index }}.visibilityOperator" label="Operator">@foreach (\App\Services\RequestTypes\RequestSchemaCompiler::CONDITION_OPERATORS as $operator)<flux:select.option :value="$operator">{{ Str::headline($operator) }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="fields.{{ $index }}.visibilityValue" label="Value" /><flux:input wire:model="fields.{{ $index }}.requiredField" label="Require when field" /><flux:select wire:model="fields.{{ $index }}.requiredOperator" label="Operator">@foreach (\App\Services\RequestTypes\RequestSchemaCompiler::CONDITION_OPERATORS as $operator)<flux:select.option :value="$operator">{{ Str::headline($operator) }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="fields.{{ $index }}.requiredValue" label="Value" /></div></details>
                    </section>
                @endforeach
            </div>
            <details class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><summary class="cursor-pointer font-semibold">{{ __('Preview form') }}</summary><div class="mt-4 space-y-4">@foreach ($fields as $field)<div><label class="text-sm font-medium">{{ $field['label'] ?: __('Untitled field') }} @if($field['is_required'])<span aria-hidden="true">*</span>@endif</label><input class="mt-1 w-full rounded-lg border bg-white p-2 dark:bg-zinc-900" placeholder="{{ $field['placeholder'] }}" disabled><p class="mt-1 text-sm text-zinc-500">{{ $field['help'] }}</p></div>@endforeach</div></details>
            <flux:error name="fields" /><div class="flex flex-wrap justify-end gap-2"><flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button wire:click="save(false)">{{ __('Save draft') }}</flux:button><flux:button variant="primary" wire:click="save(true)" dusk="publish-request-type">{{ __('Publish') }}</flux:button></div>
        </div>
    </flux:modal>
</div>
