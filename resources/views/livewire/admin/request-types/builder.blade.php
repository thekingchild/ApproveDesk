<?php

use App\Actions\RequestTypes\SaveRequestTypeAction;
use App\DTOs\RequestTypeData;
use App\Livewire\Forms\RequestFieldsForm;
use App\Models\RequestType;
use App\Models\WorkflowTemplate;
use App\Services\RequestTypes\RequestSchemaCompiler;
use App\Services\Workflow\WorkflowTemplateSelector;
use App\Support\RequestFieldKey;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * The add/edit dialog on the Request types page (ADR 0006 split, RV-ARC-05).
 * The page opens it with the open-request-type-builder event; it announces
 * request-types-changed after a save. Fields are mapped by RequestFieldsForm;
 * draft checks and the preview come from the schema compiler, and saving runs
 * in one transaction through SaveRequestTypeAction.
 */
new class extends Component {
    #[Locked]
    public ?int $editingTypeId = null;

    public string $name = '';

    public string $description = '';

    public string $workflowTemplateId = '';

    /** @var array<int, array<string, mixed>> */
    public array $selectionRules = [];

    public string $sampleMetadata = '{"amount": 15000}';

    /** @var list<array<string, mixed>> */
    public array $routingTrace = [];

    /** @var array<int, array<string, mixed>> */
    public array $fields = [];

    /** @var array<string, mixed> Sample values driving the live builder preview (P6B-UI-01). */
    public array $previewValues = [];

    public bool $localDraftAvailable = false;

    public string $localDraftSavedAt = '';

    #[Locked]
    public bool $localDraftDiscarded = false;

    public function mount(): void
    {
        $this->authorize('viewAny', RequestType::class);
    }

    #[On('open-request-type-builder')]
    public function open(?int $requestTypeId = null): void
    {
        $requestTypeId === null ? $this->createType() : $this->editType($requestTypeId);
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
        $type = RequestType::query()->with(['versions.fields', 'selectionRules'])->findOrFail($id);
        $this->authorize('update', $type);
        $this->resetBuilder();
        $this->editingTypeId = $type->id;
        $this->name = $type->name;
        $this->description = $type->description ?? '';
        $this->workflowTemplateId = $type->workflow_template_id ? (string) $type->workflow_template_id : '';
        $this->selectionRules = $type->selectionRules->map(fn ($rule): array => [
            'name' => $rule->name,
            'priority' => $rule->priority,
            'workflow_template_id' => (string) $rule->workflow_template_id,
            'is_active' => $rule->is_active,
            'field' => data_get($rule->conditions, 'rules.0.field', 'priority'),
            'operator' => data_get($rule->conditions, 'rules.0.operator', 'equals'),
            'value' => is_array(data_get($rule->conditions, 'rules.0.value'))
                ? implode(', ', data_get($rule->conditions, 'rules.0.value'))
                : (string) data_get($rule->conditions, 'rules.0.value', ''),
        ])->values()->all();
        $this->fields = $type->versions->first()?->fields->map(RequestFieldsForm::fromDefinition(...))->values()->all() ?? [];
        $this->modal('request-type-builder')->show();
    }

    /**
     * Snapshot of builder state for the per-browser local draft (P6B-UI-04).
     * Stored client-side only; the server draft remains the durable record.
     *
     * @return array<string, mixed>
     */
    public function localDraftSnapshot(): array
    {
        return [
            'editingTypeId' => $this->editingTypeId,
            'name' => $this->name,
            'description' => $this->description,
            'workflowTemplateId' => $this->workflowTemplateId,
            'fields' => $this->fields,
            'selectionRules' => $this->selectionRules,
        ];
    }

    /**
     * Restore builder state from a previously saved browser-local snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreLocalDraft(array $snapshot): void
    {
        if ($this->localDraftDiscarded || ($snapshot['editingTypeId'] ?? null) !== $this->editingTypeId) {
            return;
        }

        $this->name = (string) ($snapshot['name'] ?? '');
        $this->description = (string) ($snapshot['description'] ?? '');
        $this->workflowTemplateId = (string) ($snapshot['workflowTemplateId'] ?? '');
        $this->fields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        $this->selectionRules = is_array($snapshot['selectionRules'] ?? null) ? $snapshot['selectionRules'] : [];
        $this->localDraftAvailable = false;
        $this->localDraftSavedAt = '';
    }

    /** Explicitly drop the stored browser-local snapshot for this editing session. */
    public function discardLocalDraft(): void
    {
        $this->localDraftDiscarded = true;
        $this->localDraftAvailable = false;
        $this->localDraftSavedAt = '';
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

    public function addField(): void
    {
        $this->fields[] = RequestFieldsForm::blank();
    }

    public function duplicateField(int $index): void
    {
        if (! isset($this->fields[$index])) {
            return;
        }

        $copy = $this->fields[$index];
        $copy['label'] = trim((string) $copy['label'].' copy');
        $copy['key'] = '';
        $copy['keyAuto'] = true;
        array_splice($this->fields, $index + 1, 0, [$copy]);
        $this->fields[$index + 1]['key'] = RequestFieldKey::uniqueFromLabel((string) $copy['label'], $this->fields, $index + 1);
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
        if ($path === null || ! str_ends_with($path, '.label')) {
            return;
        }

        $index = (int) str($path)->before('.')->toString();

        if (! ($this->fields[$index]['keyAuto'] ?? (($this->fields[$index]['key'] ?? '') === ''))) {
            return;
        }

        $this->fields = RequestFieldsForm::rekeyed($this->fields, $index, RequestFieldKey::uniqueFromLabel((string) $value, $this->fields, $index));
    }

    public function importOptions(int $index): void
    {
        if (isset($this->fields[$index])) {
            $this->fields[$index]['options'] = implode("\n", RequestFieldsForm::options((string) ($this->fields[$index]['optionsCsv'] ?? '')));
        }
    }

    public function exportOptions(int $index): StreamedResponse
    {
        $options = RequestFieldsForm::options((string) ($this->fields[$index]['options'] ?? ''));

        return response()->streamDownload(function () use ($options): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['option']);
            foreach ($options as $option) {
                fputcsv($handle, [$option]);
            }
            fclose($handle);
        }, 'request-field-options.csv', ['Content-Type' => 'text/csv']);
    }

    public function toggleCalculationField(int $index, int $sourceIndex): void
    {
        $sourceKey = (string) ($this->fields[$sourceIndex]['key'] ?? '');

        if ($sourceKey === '' || ! isset($this->fields[$index])) {
            return;
        }

        $selected = RequestFieldsForm::options((string) ($this->fields[$index]['calculationFields'] ?? ''));

        $this->fields[$index]['calculationFields'] = implode(',', in_array($sourceKey, $selected, true)
            ? array_values(array_diff($selected, [$sourceKey]))
            : array_values(array_merge($selected, [$sourceKey])));
    }

    public function addSelectionRule(): void
    {
        $this->selectionRules[] = [
            'name' => 'Rule '.(count($this->selectionRules) + 1),
            'priority' => count($this->selectionRules) + 1,
            'workflow_template_id' => '',
            'is_active' => true,
            'field' => 'metadata.amount',
            'operator' => 'gte',
            'value' => '10000',
        ];
    }

    public function removeSelectionRule(int $index): void
    {
        unset($this->selectionRules[$index]);
        $this->selectionRules = array_values($this->selectionRules);
    }

    public function explainRouting(): void
    {
        if ($this->editingTypeId === null) {
            return;
        }

        $type = RequestType::query()->with(['workflowTemplate', 'selectionRules.template', 'publishedVersion'])->findOrFail($this->editingTypeId);
        $this->authorize('update', $type);
        $metadata = json_decode($this->sampleMetadata, true);
        $this->routingTrace = app(WorkflowTemplateSelector::class)->explain($type, [
            'metadata' => is_array($metadata) ? $metadata : [],
        ]);
    }

    public function save(bool $publish = false): void
    {
        $type = $this->editingTypeId !== null ? RequestType::query()->findOrFail($this->editingTypeId) : null;
        $this->authorize($type ? 'update' : 'create', $type ?? RequestType::class);
        if ($publish) {
            $this->authorize('publish', RequestType::class);
        }

        $this->fields = RequestFieldsForm::withKeys($this->fields);
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'workflowTemplateId' => $this->workflowTemplateId === ''
                ? ['nullable']
                : ['integer', Rule::exists(WorkflowTemplate::class, 'id')],
            ...RequestFieldsForm::rules(),
        ]);

        app(SaveRequestTypeAction::class)->execute($type, new RequestTypeData(
            name: $this->name,
            description: $this->description !== '' ? $this->description : null,
            fields: array_map(RequestFieldsForm::toStorage(...), $this->fields),
            workflowTemplateId: $this->workflowTemplateId !== '' ? (int) $this->workflowTemplateId : null,
            selectionRules: $this->selectionRules,
        ), Auth::user(), $publish);

        // The server draft is now durable; drop this browser's local copy.
        $this->localDraftDiscarded = true;
        $this->dispatch('builder-draft-saved');
        $this->dispatch('request-types-changed');

        $this->modal('request-type-builder')->close();
        $this->resetBuilder();
        Flux::toast($publish ? 'Request type published.' : 'Draft saved.', variant: 'success');
    }

    /**
     * Advisory draft checks (P6B-UI-02): the problems save and publish would
     * refuse, from the same schema compiler.
     *
     * @return list<array{index: int, key: string, message: string}>
     */
    #[Computed]
    public function draftWarnings(): array
    {
        return app(RequestSchemaCompiler::class)->schemaProblems(RequestFieldsForm::definitions($this->fields));
    }

    /**
     * Which preview fields show and are required for the sample values (P6B-UI-01).
     *
     * @return array<int, array{visible: bool, required: bool}>
     */
    #[Computed]
    public function previewState(): array
    {
        return app(RequestSchemaCompiler::class)->previewState(RequestFieldsForm::definitions($this->fields), $this->previewValues);
    }

    private function resetBuilder(): void
    {
        $this->resetValidation();
        $this->reset(['editingTypeId', 'name', 'description', 'workflowTemplateId', 'fields', 'selectionRules', 'routingTrace', 'previewValues']);
        $this->sampleMetadata = '{"amount": 15000}';
    }
}; ?>

<div>
<flux:modal name="request-type-builder" class="w-full max-w-6xl">
    <div class="space-y-5"
        x-data="requestTypeBuilderDraft"
        x-on:builder-draft-saved.window="discardDraft()"
    >
        @if ($localDraftAvailable)
            <div class="flex items-center justify-between gap-3 rounded-xl border border-sky-300 bg-sky-50 p-3 dark:border-sky-700 dark:bg-sky-900/30" data-test="local-draft-banner">
                <p class="text-sm text-sky-900 dark:text-sky-100">{{ __('An unsaved browser draft from :time is available for this form.', ['time' => $localDraftSavedAt]) }}</p>
                <div class="flex gap-2">
                    <flux:button size="sm" x-on:click="restoreDraft()" dusk="restore-local-draft">{{ __('Restore draft') }}</flux:button>
                    <flux:button size="sm" variant="danger" x-on:click="discardDraft()" dusk="discard-local-draft">{{ __('Discard') }}</flux:button>
                </div>
            </div>
        @endif
        <div><flux:heading size="lg">{{ $editingTypeId ? __('Edit request type') : __('New request type') }}</flux:heading><flux:subheading>{{ __('Published versions stay immutable; later edits create a new draft.') }} <span class="text-zinc-400" x-show="savedAt" x-cloak data-test="local-draft-indicator">{{ __('Browser draft saved') }} <span x-text="savedAt"></span></span></flux:subheading></div>
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
                <p class="mt-1 text-sm text-zinc-500">{{ __('New submissions of this form start this approval chain unless a selection rule below matches first. In-flight requests keep the chain they already have.') }}</p>
                <flux:error name="workflowTemplateId" />
            </div>
        </div>
        <div class="space-y-3" data-test="selection-rules">
            <div class="flex items-center justify-between">
                <flux:heading size="md">{{ __('Selection rules') }}</flux:heading>
                <flux:button size="sm" wire:click="addSelectionRule" data-test="add-selection-rule">{{ __('Add rule') }}</flux:button>
            </div>
            <p class="text-sm text-zinc-500">{{ __('Rules run in priority order (1 first). The first match wins, then the assigned template, then the default. Document / Note / Memo / Request are not routing keys.') }}</p>
            @foreach ($selectionRules as $index => $rule)
                <div class="grid gap-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700 md:grid-cols-6" wire:key="rule-{{ $index }}">
                    <flux:input wire:model="selectionRules.{{ $index }}.priority" type="number" min="1" :label="__('Priority')" />
                    <flux:input wire:model="selectionRules.{{ $index }}.name" :label="__('Name')" />
                    <flux:select wire:model="selectionRules.{{ $index }}.workflow_template_id" :label="__('Template')">
                        <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                        @foreach ($this->workflowTemplates as $template)
                            <flux:select.option :value="$template->id">{{ $template->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="selectionRules.{{ $index }}.field" :label="__('Field')" placeholder="metadata.amount or fields.amount" />
                    <flux:select wire:model="selectionRules.{{ $index }}.operator" :label="__('Operator')">
                        @foreach (\App\Livewire\Forms\StepConditionForm::OPERATORS as $operator => $operatorLabel)
                            <flux:select.option :value="$operator">{{ __($operatorLabel) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="flex items-end gap-2">
                        <flux:input wire:model="selectionRules.{{ $index }}.value" :label="__('Value')" />
                        <flux:button size="xs" variant="danger" wire:click="removeSelectionRule({{ $index }})">{{ __('Remove') }}</flux:button>
                    </div>
                    <div class="md:col-span-6">
                        <flux:error name="rules.{{ $index }}.name" />
                        <flux:error name="rules.{{ $index }}.workflow_template_id" />
                        <flux:error name="rules.{{ $index }}.field" />
                    </div>
                </div>
            @endforeach
            @if ($editingTypeId)
                <div class="grid gap-3 md:grid-cols-[1fr_auto]">
                    <flux:input wire:model="sampleMetadata" :label="__('Sample metadata JSON')" />
                    <flux:button wire:click="explainRouting" data-test="explain-routing">{{ __('Explain routing') }}</flux:button>
                </div>
                @if ($routingTrace !== [])
                    <ol class="space-y-1 text-sm" data-test="routing-trace">
                        @foreach ($routingTrace as $entry)
                            <li>{{ $entry['name'] }} → {{ $entry['template'] }} ({{ $entry['matched'] ? __('match') : __('no match') }})</li>
                        @endforeach
                    </ol>
                @endif
            @endif
        </div>
        <div class="flex items-center justify-between"><flux:heading size="md">{{ __('Fields') }}</flux:heading><flux:button wire:click="addField" icon="plus" dusk="add-field">{{ __('Add field') }}</flux:button></div>
        <div class="space-y-4" wire:sort="sortField">
            @foreach ($fields as $index => $field)
                <x-request-types.field-editor :field="$field" :index="$index" :fields="$fields" />
            @endforeach
        </div>
        <x-request-types.form-preview :fields="$fields" :preview-state="$this->previewState" :preview-values="$previewValues" />
        @if ($this->draftWarnings !== [])
            <div class="space-y-1 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/30" data-test="draft-warnings">
                <p class="font-medium text-amber-800 dark:text-amber-200">{{ __('Draft checks') }}</p>
                @foreach ($this->draftWarnings as $warning)
                    <button type="button" class="block text-left text-sm text-amber-800 underline decoration-dotted dark:text-amber-200" wire:click="\$dispatch('scroll-to-field', { index: {{ $warning['index'] }} })" data-test="draft-warning">
                        {{ __('Field') }} {{ $warning['index'] + 1 }}: {{ $warning['message'] }}
                    </button>
                @endforeach
            </div>
        @endif
        <flux:error name="fields" /><div class="flex flex-wrap justify-end gap-2"><flux:modal.close><flux:button>{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button wire:click="save(false)">{{ __('Save draft') }}</flux:button><flux:button variant="primary" wire:click="save(true)" dusk="publish-request-type">{{ __('Publish') }}</flux:button></div>
    </div>
</flux:modal>
</div>
