<?php

use App\Actions\RequestTypes\ArchiveRequestTypeAction;
use App\Actions\RequestTypes\AssignRequestTypeWorkflowAction;
use App\Actions\WorkflowTemplates\SaveWorkflowSelectionRulesAction;
use App\Services\Workflow\WorkflowTemplateSelector;
use App\Actions\RequestTypes\CloneRequestTypeAction;
use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\RequestTypes\RestoreRequestTypeAction;
use App\Actions\RequestTypes\SaveRequestTypeDraftAction;
use App\Enums\RequestFieldType;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\WorkflowTemplate;
use App\Support\RequestFieldKey;
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

    /**
     * Snapshot of builder state for the per-browser local draft (P6B-UI-04).
     * Stored client-side only; the server draft remains the durable record.
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

    /** Restore builder state from a previously saved browser-local snapshot. */
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

    #[Computed]
    public function requestTypes(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
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
        $type = RequestType::query()->with(['versions.fields', 'selectionRules'])->findOrFail($id);
        $this->authorize('update', $type);
        $version = $type->versions->first();
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
        $this->routingTrace = [];
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
        $copy['label'] = trim((string) $copy['label'].' copy');
        $copy['key'] = '';
        $copy['keyAuto'] = true;
        array_splice($this->fields, $index + 1, 0, [$copy]);
        $newIndex = $index + 1;
        $this->fields[$newIndex]['key'] = RequestFieldKey::uniqueFromLabel(
            (string) $this->fields[$newIndex]['label'],
            $this->fields,
            $newIndex,
        );
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

        $oldKey = (string) ($this->fields[$index]['key'] ?? '');
        $newKey = RequestFieldKey::uniqueFromLabel((string) $value, $this->fields, $index);
        $this->fields[$index]['key'] = $newKey;

        if ($oldKey !== '' && $oldKey !== $newKey) {
            $this->retargetFieldKeys($oldKey, $newKey);
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

    public function toggleCalculationField(int $index, int $sourceIndex): void
    {
        $sourceKey = (string) ($this->fields[$sourceIndex]['key'] ?? '');

        if ($sourceKey === '' || ! isset($this->fields[$index])) {
            return;
        }

        $selected = $this->options((string) ($this->fields[$index]['calculationFields'] ?? ''));

        $this->fields[$index]['calculationFields'] = implode(',', in_array($sourceKey, $selected, true)
            ? array_values(array_diff($selected, [$sourceKey]))
            : array_values(array_merge($selected, [$sourceKey])));
    }

    public function save(bool $publish = false): void
    {
        if ($publish) {
            $this->authorize('publish', RequestType::class);
        }
        $this->authorize($this->editingTypeId ? 'update' : 'create', $this->editingTypeId ? RequestType::findOrFail($this->editingTypeId) : RequestType::class);
        $this->ensureFieldKeys();
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

        app(SaveWorkflowSelectionRulesAction::class)->execute(
            $version->requestType,
            $this->selectionRules,
            auth()->user(),
        );

        if ($publish) {
            app(PublishRequestTypeVersionAction::class)->execute($version);
        }

        // The server draft is now durable; drop this browser's local copy.
        $this->localDraftDiscarded = true;
        $this->dispatch('builder-draft-saved');

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
        app(CloneRequestTypeAction::class)->execute($source, auth()->user());
        unset($this->requestTypes);
        Flux::toast('Request type cloned as a draft.', variant: 'success');
    }

    /** @return array<string, mixed> */
    private function blankField(): array
    {
        return [
            'label' => '', 'key' => '', 'keyAuto' => true, 'type' => RequestFieldType::ShortText->value, 'is_required' => false,
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
            'label' => $field->label, 'key' => $field->key, 'keyAuto' => false, 'type' => $field->type->value, 'is_required' => $field->is_required,
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
            // Date bounds stay strings (YYYY-MM-DD from the picker); numeric
            // bounds are cast to float. Never cast a date bound to a number.
            'min' => $field['min'] !== '' ? (in_array($field['type'], ['date', 'date_time'], true) ? (string) $field['min'] : (float) $field['min']) : null,
            'max' => $field['max'] !== '' ? (in_array($field['type'], ['date', 'date_time'], true) ? (string) $field['max'] : (float) $field['max']) : null,
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

    private function ensureFieldKeys(): void
    {
        foreach ($this->fields as $index => $field) {
            $oldKey = trim((string) ($field['key'] ?? ''));
            $auto = (bool) ($field['keyAuto'] ?? $oldKey === '');

            if (! $auto && $oldKey !== '') {
                continue;
            }

            $newKey = RequestFieldKey::uniqueFromLabel((string) ($field['label'] ?? ''), $this->fields, $index);
            $this->fields[$index]['key'] = $newKey;

            if ($oldKey !== '' && $oldKey !== $newKey) {
                $this->retargetFieldKeys($oldKey, $newKey);
            }
        }
    }

    private function retargetFieldKeys(string $oldKey, string $newKey): void
    {
        foreach ($this->fields as $index => $field) {
            if (($field['visibilityField'] ?? '') === $oldKey) {
                $this->fields[$index]['visibilityField'] = $newKey;
            }

            if (($field['requiredField'] ?? '') === $oldKey) {
                $this->fields[$index]['requiredField'] = $newKey;
            }

            $selected = $this->options((string) ($field['calculationFields'] ?? ''));
            $this->fields[$index]['calculationFields'] = implode(',', array_map(
                fn (string $key): string => $key === $oldKey ? $newKey : $key,
                $selected,
            ));
        }
    }

    private function resetBuilder(): void
    {
        $this->resetValidation();
        $this->reset(['editingTypeId', 'name', 'description', 'workflowTemplateId', 'fields', 'selectionRules', 'routingTrace']);
        $this->sampleMetadata = '{"amount": 15000}';
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
        $metadata = json_decode($this->sampleMetadata, true);
        $this->routingTrace = app(WorkflowTemplateSelector::class)->explain($type, [
            'metadata' => is_array($metadata) ? $metadata : [],
        ]);
    }

    /**
     * Advisory draft-time warnings for the builder (P6B-UI-02). Server-side
     * validation at save/publish remains the authority.
     *
     * @return list<array{index: int, message: string}>
     */
    #[Computed]
    public function draftWarnings(): array
    {
        $warnings = [];
        $keysByIndex = [];

        foreach ($this->fields as $index => $field) {
            $keysByIndex[$index] = (string) ($field['key'] ?? '');
        }

        foreach ($this->fields as $index => $field) {
            $type = (string) ($field['type'] ?? '');

            if (in_array($type, ['select', 'multi_select', 'radio'], true) && $this->options((string) ($field['options'] ?? '')) === []) {
                $warnings[] = ['index' => $index, 'message' => __('Choice fields need at least one option before publishing.')];
            }

            if (in_array($type, ['select', 'radio'], true)) {
                $default = trim((string) ($field['default'] ?? ''));
                $choices = $this->options((string) ($field['options'] ?? ''));

                if ($default !== '' && $choices !== [] && ! in_array($default, $choices, true)) {
                    $warnings[] = ['index' => $index, 'message' => __('The default value is not one of the field options.')];
                }
            }

            if (in_array($type, ['date', 'date_time'], true)) {
                $min = (string) ($field['min'] ?? '');
                $max = (string) ($field['max'] ?? '');

                foreach (['earliest' => $min, 'latest' => $max] as $boundLabel => $bound) {
                    if ($bound !== '' && (strtotime($bound) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $bound) !== 1)) {
                        $warnings[] = ['index' => $index, 'message' => __('The :label date limit is not a real date. Pick dates from the calendar instead of typing numbers.', ['label' => $boundLabel])];
                    }
                }

                if ($min !== '' && $max !== '' && strtotime($min) !== false && strtotime($max) !== false && strtotime($min) > strtotime($max)) {
                    $warnings[] = ['index' => $index, 'message' => __('The earliest date is after the latest date.')];
                }
            }

            foreach (['visibilityField' => __('visibility'), 'requiredField' => __('required')] as $sourceProperty => $ruleLabel) {
                $sourceKey = (string) ($field[$sourceProperty] ?? '');

                if ($sourceKey === '') {
                    continue;
                }

                $sourceIndex = null;

                foreach ($keysByIndex as $otherIndex => $key) {
                    if ($key === $sourceKey && $otherIndex < $index) {
                        $sourceIndex = $otherIndex;
                        break;
                    }
                }

                if ($sourceIndex === null) {
                    $warnings[] = ['index' => $index, 'message' => __('The :label rule references an earlier field that no longer exists.', ['label' => $ruleLabel])];
                }
            }

            if ($type === 'calculated' && $this->options((string) ($field['calculationFields'] ?? '')) === []) {
                $warnings[] = ['index' => $index, 'message' => __('Calculated fields must sum at least one earlier number or currency field.')];
            }
        }

        return $warnings;
    }

    /** Whether a field passes its visibility rule against the preview values (P6B-UI-01). */
    public function previewVisible(int $index): bool
    {
        $field = $this->fields[$index] ?? null;

        if ($field === null || (string) ($field['visibilityField'] ?? '') === '') {
            return true;
        }

        $actual = $this->previewValue((string) $field['visibilityField']);
        $expected = (string) ($field['visibilityValue'] ?? '');

        return match ((string) $field['visibilityOperator']) {
            'equals' => (string) $actual === $expected,
            'not_equals' => (string) $actual !== $expected,
            'in' => in_array((string) $actual, array_map('strval', (array) ($field['visibilityValue'] ?? [])), true),
            'not_empty' => ! blank($actual),
            default => true,
        };
    }

    /** A hidden field's sample value counts as empty, as on the real form (RV-BUG-29). */
    private function previewValue(string $key): mixed
    {
        foreach ($this->fields as $index => $field) {
            if (($field['key'] ?? null) === $key && ! $this->previewVisible($index)) {
                return '';
            }
        }

        return $this->previewValues[$key] ?? '';
    }

    /** Whether a visible field is currently required against the preview values (P6B-UI-01). */
    public function previewRequired(int $index): bool
    {
        $field = $this->fields[$index] ?? null;

        if ($field === null || ! $this->previewVisible($index)) {
            return false;
        }

        if ((bool) ($field['is_required'] ?? false)) {
            return true;
        }

        if ((string) ($field['requiredField'] ?? '') === '') {
            return false;
        }

        $actual = $this->previewValue((string) $field['requiredField']);

        return match ((string) $field['requiredOperator']) {
            'equals' => (string) $actual === (string) ($field['requiredValue'] ?? ''),
            'not_equals' => (string) $actual !== (string) ($field['requiredValue'] ?? ''),
            'not_empty' => ! blank($actual),
            default => false,
        };
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
                    <flux:table.cell><div class="flex flex-wrap justify-end gap-2"><flux:button size="sm" wire:click="editType({{ $type->id }})">{{ __('Edit') }}</flux:button><flux:button size="sm" wire:click="cloneType({{ $type->id }})">{{ __('Clone') }}</flux:button>@if ($type->is_active)<flux:button size="sm" variant="danger" wire:click="archive({{ $type->id }})" wire:confirm="Archive this request type?">{{ __('Archive') }}</flux:button>@else<flux:button size="sm" wire:click="restore({{ $type->id }})" wire:confirm="Restore this request type so people can submit it again?">{{ __('Restore') }}</flux:button>@endif</div></flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="5"><x-ui.empty-state title="No custom request types" description="Create the first versioned request form." /></flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="request-type-builder" class="w-full max-w-6xl">
        <div class="space-y-5"
            x-data="requestTypeBuilderDraft({
                key: 'approvedesk.request-type-builder.' + ($wire.editingTypeId ?? 'new'),
                snapshot: () => $wire.localDraftSnapshot(),
                restore: (draft) => $wire.restoreLocalDraft(draft),
                discardFlag: () => $wire.discardLocalDraft(),
                onReady: (api) => { window.__builderDraft = api },
            })"
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
                            @foreach (\App\Support\StepConditionForm::OPERATORS as $operator => $operatorLabel)
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
                    <section wire:key="field-{{ $index }}" wire:sort:item="{{ $index }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" dusk="builder-field-{{ $index }}">
                        <div class="mb-3 flex items-center justify-between"><div class="font-semibold" wire:sort:handle>⋮⋮ {{ __('Field') }} {{ $index + 1 }}</div><div class="flex gap-1"><flux:button size="sm" wire:click="moveField({{ $index }}, -1)" aria-label="Move field up">↑</flux:button><flux:button size="sm" wire:click="moveField({{ $index }}, 1)" aria-label="Move field down">↓</flux:button><flux:button size="sm" wire:click="duplicateField({{ $index }})">{{ __('Duplicate') }}</flux:button><flux:button size="sm" variant="danger" wire:click="removeField({{ $index }})">{{ __('Delete') }}</flux:button></div></div>
                        <div class="grid gap-3 md:grid-cols-3">
                            <flux:input wire:model.live.debounce.400ms="fields.{{ $index }}.label" label="Label" required dusk="field-label-{{ $index }}" />
                            <flux:select wire:model.live="fields.{{ $index }}.type" label="Type" dusk="field-type-{{ $index }}">@foreach (RequestFieldType::cases() as $case)<flux:select.option :value="$case->value">{{ Str::headline($case->value) }}</flux:select.option>@endforeach</flux:select>
                            <flux:input wire:model="fields.{{ $index }}.section" label="Section" /><flux:input wire:model="fields.{{ $index }}.placeholder" label="Placeholder" /><flux:input wire:model="fields.{{ $index }}.help" label="Help text" />
                            <flux:input wire:model="fields.{{ $index }}.instructions" label="Instructions" /><flux:input wire:model="fields.{{ $index }}.default" label="Default value" /><flux:checkbox wire:model="fields.{{ $index }}.is_required" label="Always required" dusk="field-required-{{ $index }}" />
                            <flux:input wire:model="fields.{{ $index }}.maxLength" type="number" label="Maximum length" />
                            @if (in_array($field['type'], ['date', 'date_time'], true))
                                <flux:input wire:model="fields.{{ $index }}.min" type="date" :label="__('Earliest date requesters can choose')" dusk="field-min-{{ $index }}" />
                                <flux:input wire:model="fields.{{ $index }}.max" type="date" :label="__('Latest date requesters can choose')" dusk="field-max-{{ $index }}" />
                            @elseif (in_array($field['type'], ['number', 'currency'], true))
                                <flux:input wire:model="fields.{{ $index }}.min" type="number" :label="__('Minimum value')" dusk="field-min-{{ $index }}" />
                                <flux:input wire:model="fields.{{ $index }}.max" type="number" :label="__('Maximum value')" dusk="field-max-{{ $index }}" />
                            @endif
                        </div>
                        @if (in_array($field['type'], ['date', 'date_time'], true) && strtotime((string) ($field['min'] ?? '')) !== false || in_array($field['type'], ['date', 'date_time'], true) && strtotime((string) ($field['max'] ?? '')) !== false)
                            <p class="mt-2 text-sm text-zinc-500" data-test="bounds-summary-{{ $index }}">
                                {{ __('Requesters can pick dates') }}
                                @if (strtotime((string) ($field['min'] ?? '')) !== false) {{ __('from') }} <strong>{{ \Carbon\Carbon::parse($field['min'])->format('j M Y') }}</strong> @endif
                                @if (strtotime((string) ($field['max'] ?? '')) !== false) {{ __('up to') }} <strong>{{ \Carbon\Carbon::parse($field['max'])->format('j M Y') }}</strong> @endif
                            </p>
                        @endif
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
                        @if ($field['type'] === 'calculated')
                            <fieldset class="mt-3 space-y-2">
                                <legend class="text-sm font-medium">{{ __('Fields to sum') }}</legend>
                                @foreach ($fields as $sourceIndex => $source)
                                    @if ($sourceIndex < $index && in_array($source['type'], ['number', 'currency'], true) && ($source['key'] ?? '') !== '')
                                        <flux:checkbox
                                            :checked="in_array($source['key'], $this->options($field['calculationFields'] ?? ''), true)"
                                            wire:click="toggleCalculationField({{ $index }}, {{ $sourceIndex }})"
                                            :label="$source['label'] ?: __('Untitled field')"
                                        />
                                    @endif
                                @endforeach
                                @if (collect($fields)->take($index)->whereIn('type', ['number', 'currency'])->isEmpty())
                                    <p class="text-sm text-zinc-500">{{ __('Add a number or currency field above this one first.') }}</p>
                                @endif
                            </fieldset>
                        @endif
                        <details class="mt-3"><summary class="cursor-pointer font-medium">{{ __('Conditional rules') }}</summary>
                            <div class="mt-3 grid gap-3 md:grid-cols-3">
                                <flux:select wire:model="fields.{{ $index }}.visibilityField" :label="__('Show when')">
                                    <flux:select.option value="">{{ __('Always show') }}</flux:select.option>
                                    @foreach ($fields as $sourceIndex => $source)
                                        @if ($sourceIndex < $index && ($source['key'] ?? '') !== '')
                                            <flux:select.option :value="$source['key']">{{ $source['label'] ?: __('Untitled field') }}</flux:select.option>
                                        @endif
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model="fields.{{ $index }}.visibilityOperator" label="Operator">@foreach (\App\Services\RequestTypes\RequestSchemaCompiler::CONDITION_OPERATORS as $operator)<flux:select.option :value="$operator">{{ Str::headline($operator) }}</flux:select.option>@endforeach</flux:select>
                                <div>
                                    @php($conditionSource = collect($fields)->take($index)->firstWhere('key', $field['visibilityField'] ?? ''))
                                    @if (in_array($conditionSource['type'] ?? '', ['select', 'multi_select', 'radio'], true))
                                        <flux:select wire:model="fields.{{ $index }}.visibilityValue" label="Value">
                                            <flux:select.option value="">—</flux:select.option>
                                            @foreach ($this->options((string) ($conditionSource['options'] ?? '')) as $choice)
                                                <flux:select.option :value="$choice">{{ $choice }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @else
                                        <flux:input wire:model="fields.{{ $index }}.visibilityValue" label="Value" />
                                    @endif
                                </div>
                                <flux:select wire:model="fields.{{ $index }}.requiredField" :label="__('Require when')">
                                    <flux:select.option value="">{{ __('Use the required checkbox only') }}</flux:select.option>
                                    @foreach ($fields as $sourceIndex => $source)
                                        @if ($sourceIndex < $index && ($source['key'] ?? '') !== '')
                                            <flux:select.option :value="$source['key']">{{ $source['label'] ?: __('Untitled field') }}</flux:select.option>
                                        @endif
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model="fields.{{ $index }}.requiredOperator" label="Operator">@foreach (\App\Services\RequestTypes\RequestSchemaCompiler::CONDITION_OPERATORS as $operator)<flux:select.option :value="$operator">{{ Str::headline($operator) }}</flux:select.option>@endforeach</flux:select>
                                <div>
                                    @php($requiredSource = collect($fields)->take($index)->firstWhere('key', $field['requiredField'] ?? ''))
                                    @if (in_array($requiredSource['type'] ?? '', ['select', 'multi_select', 'radio'], true))
                                        <flux:select wire:model="fields.{{ $index }}.requiredValue" label="Value">
                                            <flux:select.option value="">—</flux:select.option>
                                            @foreach ($this->options((string) ($requiredSource['options'] ?? '')) as $choice)
                                                <flux:select.option :value="$choice">{{ $choice }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @else
                                        <flux:input wire:model="fields.{{ $index }}.requiredValue" label="Value" />
                                    @endif
                                </div>
                            </div>
                        </details>
                    </section>
                @endforeach
            </div>
            <details class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" dusk="builder-preview">
                <summary class="cursor-pointer font-semibold">{{ __('Preview form') }}</summary>
                <p class="mt-2 text-sm text-zinc-500">{{ __('Enter sample values to see visibility and required rules behave exactly as requesters will experience them. This preview does not save anything.') }}</p>
                <div class="mt-4 space-y-4">
                    @foreach ($fields as $index => $field)
                        @if ($this->previewVisible($index))
                            <div wire:key="preview-field-{{ $index }}" data-test="preview-field-{{ $index }}">
                                @php($required = $this->previewRequired($index))
                                @if (in_array($field['type'], ['select', 'radio'], true))
                                    <flux:select
                                        wire:model.live="previewValues.{{ $field['key'] }}"
                                        :label="$field['label'] ?: __('Untitled field')"
                                        :required="$required"
                                        :description="$field['help']"
                                    >
                                        <flux:select.option value="">—</flux:select.option>
                                        @foreach ($this->options((string) ($field['options'] ?? '')) as $choice)
                                            <flux:select.option :value="$choice">{{ $choice }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @elseif ($field['type'] === 'multi_select')
                                    <flux:checkbox.group wire:model.live="previewValues.{{ $field['key'] }}" :label="$field['label'] ?: __('Untitled field')" :description="$field['help']">
                                        @foreach ($this->options((string) ($field['options'] ?? '')) as $choice)
                                            <flux:checkbox :value="$choice" :label="$choice" />
                                        @endforeach
                                    </flux:checkbox.group>
                                @elseif ($field['type'] === 'checkbox')
                                    <flux:checkbox wire:model.live="previewValues.{{ $field['key'] }}" :label="$field['label'] ?: __('Untitled field')" :checked="(bool) ($field['default'] ?? false)" />
                                @elseif (in_array($field['type'], ['number', 'currency'], true))
                                    <flux:input
                                        wire:model.live="previewValues.{{ $field['key'] }}"
                                        type="number"
                                        :label="$field['label'] ?: __('Untitled field')"
                                        :placeholder="$field['placeholder']"
                                        :description="$field['help']"
                                    />
                                @elseif (in_array($field['type'], ['date', 'date_time'], true))
                                    <flux:input
                                        wire:model.live="previewValues.{{ $field['key'] }}"
                                        type="{{ $field['type'] === 'date' ? 'date' : 'datetime-local' }}"
                                        :label="$field['label'] ?: __('Untitled field')"
                                        :description="$field['help']"
                                    />
                                @elseif ($field['type'] === 'long_text' || $field['type'] === 'rich_text')
                                    <flux:textarea
                                        wire:model.live="previewValues.{{ $field['key'] }}"
                                        :label="$field['label'] ?: __('Untitled field')"
                                        :placeholder="$field['placeholder']"
                                        :description="$field['help']"
                                    />
                                @elseif (in_array($field['type'], ['calculated', 'json', 'file', 'user', 'department'], true))
                                    <div>
                                        <label class="text-sm font-medium">{{ $field['label'] ?: __('Untitled field') }} @if($required)<span aria-hidden="true">*</span>@endif</label>
                                        <p class="mt-1 text-sm text-zinc-500">{{ __('This field renders fully on the live form.') }} {{ $field['help'] }}</p>
                                    </div>
                                @else
                                    <flux:input
                                        wire:model.live="previewValues.{{ $field['key'] }}"
                                        :label="$field['label'] ?: __('Untitled field')"
                                        :placeholder="$field['placeholder']"
                                        :description="$field['help']"
                                    />
                                @endif
                                @if ($required && blank($this->previewValues[$field['key']] ?? null))
                                    <p class="mt-1 text-sm text-amber-600 dark:text-amber-400">{{ __('Required by the current conditional rule.') }}</p>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            </details>
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
