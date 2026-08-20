<?php

use App\Actions\WorkflowTemplates\CloneWorkflowTemplateAction;
use App\Actions\WorkflowTemplates\CreateWorkflowTemplateAction;
use App\Actions\WorkflowTemplates\PublishWorkflowTemplateVersionAction;
use App\Actions\WorkflowTemplates\UpdateWorkflowTemplateAction;
use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\SubmissionType;
use App\Enums\TimeoutAction;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\AssigneeDirectory;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

new #[Title('Workflow templates')] class extends Component {
    use WithPagination;

    public string $search = '';

    public ?int $editingTemplateId = null;

    public bool $editingTemplateUsed = false;

    public string $changeSummary = '';

    public ?int $editingVersionNumber = null;

    public ?string $editingVersionStatus = null;

    public string $name = '';

    public string $description = '';

    /** @var array<int, string> */
    public array $submissionTypes = [];

    public bool $isDefault = false;

    public bool $isActive = true;

    /** @var array<int, array<string, mixed>> */
    public array $steps = [];

    public function mount(): void
    {
        $this->authorize('viewAny', WorkflowTemplate::class);
    }

    #[Computed]
    public function templates(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
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

    public function addAssignee(int $index, int $id): void
    {
        if (! isset($this->steps[$index])) {
            return;
        }
        $ids = array_map('intval', $this->steps[$index]['assigneeIds'] ?? []);
        $ids[] = $id;
        $this->steps[$index]['assigneeIds'] = array_values(array_unique($ids));
        $this->steps[$index]['assigneeQuery'] = '';
    }

    public function removeAssignee(int $index, int $id): void
    {
        if (! isset($this->steps[$index])) {
            return;
        }
        $this->steps[$index]['assigneeIds'] = array_values(array_filter(
            array_map('intval', $this->steps[$index]['assigneeIds'] ?? []),
            fn (int $assigned): bool => $assigned !== $id,
        ));
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public function assigneeResultsFor(int $index): \Illuminate\Support\Collection
    {
        $step = $this->steps[$index] ?? [];

        return app(AssigneeDirectory::class)->search(
            (string) ($step['assigneeType'] ?? 'user'),
            (string) ($step['assigneeQuery'] ?? ''),
            $step['assigneeIds'] ?? [],
        );
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public function selectedAssigneesFor(int $index): \Illuminate\Support\Collection
    {
        $step = $this->steps[$index] ?? [];

        return app(AssigneeDirectory::class)->selected(
            (string) ($step['assigneeType'] ?? 'user'),
            $step['assigneeIds'] ?? [],
        );
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
        $this->resetForm();
        $this->modal('template-form')->show();
    }

    public function editTemplate(int $templateId): void
    {
        $template = WorkflowTemplate::query()
            ->with(['publishedVersion.steps', 'draftVersion.steps'])
            ->withCount('instances')
            ->findOrFail($templateId);
        $this->authorize('update', $template);

        $graph = $template->draftVersion ?? $template->publishedVersion;

        $this->resetValidation();
        $this->editingTemplateId = $template->id;
        $this->editingTemplateUsed = $template->instances_count > 0;
        $this->editingVersionNumber = $graph?->version;
        $this->editingVersionStatus = $graph?->status->value;
        $this->changeSummary = $graph?->change_summary ?? '';
        $this->name = $template->name;
        $this->description = $template->description ?? '';
        $this->submissionTypes = $template->submission_types ?? [];
        $this->isDefault = $template->is_default;
        $this->isActive = $template->is_active;
        $this->steps = ($graph?->steps ?? collect())->map(fn ($step) => [
            'name' => $step->name,
            'stepType' => $step->step_type->value,
            'quorumCount' => $step->quorum_count,
            'assigneeType' => $step->assignee_type->value,
            'assigneeIds' => $step->assignee_ids,
            'assigneeQuery' => '',
            'assigneeField' => data_get($step->assignee_config, 'field_key', ''),
            'assigneeResolver' => data_get($step->assignee_config, 'resolver', ''),
            'allowDelegation' => $step->allow_delegation,
            'conditionField' => data_get($step->conditions, 'rules.0.field', 'priority'),
            'conditionOperator' => data_get($step->conditions, 'rules.0.operator', 'equals'),
            'conditionValue' => is_array(data_get($step->conditions, 'rules.0.value'))
                ? implode(', ', data_get($step->conditions, 'rules.0.value'))
                : (string) data_get($step->conditions, 'rules.0.value', ''),
            'trueStep' => data_get($step->conditions, 'true_step'),
            'falseStep' => data_get($step->conditions, 'false_step'),
            'timeoutHours' => $step->timeout_hours,
            'timeoutAction' => $step->timeout_action?->value ?? '',
            'timeoutCalendar' => $step->timeoutCalendar()->value,
        ])->values()->all();

        $this->modal('template-form')->show();
    }

    public function addStep(): void
    {
        $this->steps[] = $this->blankStep(count($this->steps) + 1);
    }

    public function removeStep(int $index): void
    {
        if (count($this->steps) <= 1) {
            return;
        }

        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function moveStep(int $index, int $direction): void
    {
        $destination = $index + $direction;

        if (! isset($this->steps[$index], $this->steps[$destination])) {
            return;
        }

        [$this->steps[$index], $this->steps[$destination]] = [$this->steps[$destination], $this->steps[$index]];
    }

    public function resetAssignees(int $index): void
    {
        $this->steps[$index]['assigneeIds'] = [];
    }

    public function save(): void
    {
        $target = $this->editingTemplateId
            ? WorkflowTemplate::query()->findOrFail($this->editingTemplateId)
            : null;

        $this->authorize($target ? 'update' : 'create', $target ?? WorkflowTemplate::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique(WorkflowTemplate::class, 'name')->ignore($target)],
            'description' => ['nullable', 'string', 'max:5000'],
            'submissionTypes' => ['array'],
            'submissionTypes.*' => ['string', Rule::enum(SubmissionType::class)],
            'isDefault' => ['required', 'boolean'],
            'isActive' => ['required', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.name' => ['required', 'string', 'max:255'],
            'steps.*.stepType' => ['required', Rule::in([
                StepType::Sequential->value,
                StepType::Parallel->value,
                StepType::Quorum->value,
                StepType::Conditional->value,
                StepType::Auto->value,
                StepType::Notification->value,
            ])],
            'steps.*.quorumCount' => ['nullable', 'integer', 'min:1'],
            'steps.*.assigneeType' => ['required', Rule::enum(AssigneeType::class)],
            'steps.*.assigneeIds' => ['array'],
            'steps.*.assigneeIds.*' => ['required', 'integer'],
            'steps.*.allowDelegation' => ['sometimes', 'boolean'],
            'steps.*.conditionField' => ['nullable', 'string', 'max:100'],
            'steps.*.conditionOperator' => ['nullable', Rule::in([
                'equals', 'not_equals', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'contains', 'present',
            ])],
            'steps.*.conditionValue' => ['nullable', 'string', 'max:1000'],
            'steps.*.trueStep' => ['nullable', 'integer', 'min:1', 'max:20'],
            'steps.*.falseStep' => ['nullable', 'integer', 'min:1', 'max:20'],
            'steps.*.timeoutHours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'steps.*.timeoutAction' => ['nullable', Rule::enum(TimeoutAction::class)],
        ]);

        if ($validated['isDefault'] && ! $validated['isActive']) {
            throw ValidationException::withMessages([
                'isActive' => __('The default workflow template must be active.'),
            ]);
        }

        if (! $validated['isDefault'] && $validated['submissionTypes'] === []) {
            $validated['submissionTypes'] = [];
        }

        $stepCount = count($validated['steps']);
        $stepData = collect($validated['steps'])->map(function (array $step, int $index) use ($stepCount): WorkflowTemplateStepData {
            $type = AssigneeType::from($step['assigneeType']);
            $ids = array_values(array_unique(array_map('intval', $step['assigneeIds'])));
            $stepType = StepType::from($step['stepType']);
            $automatic = $stepType->isAutomatic();
            $assigneeCount = 0;

            $assigneeConfig = match ($type) {
                AssigneeType::FormField => ['field_key' => trim((string) ($step['assigneeField'] ?? ''))],
                AssigneeType::External => ['resolver' => trim((string) ($step['assigneeResolver'] ?? ''))],
                default => null,
            };

            if (! $automatic && $type->requiresExplicitAssignees()) {
                $this->guardAssigneesExist($type, $ids, $index);
                $assigneeCount = $this->resolvedAssigneeCount($type, $ids);

                if ($assigneeCount < 1) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.assigneeIds" => __('Select an assignment with at least one active user.'),
                    ]);
                }
            }

            if (! $automatic && $type === AssigneeType::FormField && ($assigneeConfig['field_key'] ?? '') === '') {
                throw ValidationException::withMessages([
                    "steps.{$index}.assigneeField" => __('Enter the user field key from the form.'),
                ]);
            }

            $quorumCount = isset($step['quorumCount']) && $step['quorumCount'] !== ''
                ? (int) $step['quorumCount']
                : null;

            if ($stepType === StepType::Quorum && $type->requiresExplicitAssignees()) {
                $this->guardQuorumIsPossible($quorumCount, $assigneeCount, $index);
            } elseif ($stepType === StepType::Quorum && ($quorumCount === null || $quorumCount < 1)) {
                throw ValidationException::withMessages([
                    "steps.{$index}.quorumCount" => __('Quorum must be at least 1.'),
                ]);
            }

            $conditions = $stepType === StepType::Conditional
                ? $this->conditionalConfiguration($step, $index, $stepCount)
                : null;
            $timeoutHours = ! $automatic && filled($step['timeoutHours'] ?? null)
                ? (int) $step['timeoutHours']
                : null;
            $timeoutAction = ! $automatic && filled($step['timeoutAction'] ?? null)
                ? TimeoutAction::from($step['timeoutAction'])
                : null;

            if (($timeoutHours === null) !== ($timeoutAction === null)) {
                throw ValidationException::withMessages([
                    "steps.{$index}.timeoutHours" => __('Set both an SLA duration and timeout action, or leave both empty.'),
                ]);
            }

            if ($timeoutAction === TimeoutAction::Escalate && $index + 1 >= $stepCount) {
                throw ValidationException::withMessages([
                    "steps.{$index}.timeoutAction" => __('The final step cannot escalate because no later step exists.'),
                ]);
            }

            return new WorkflowTemplateStepData(
                name: $step['name'],
                stepType: $stepType,
                quorumCount: $stepType === StepType::Quorum ? $quorumCount : null,
                assigneeType: $type,
                assigneeIds: $automatic || ! $type->requiresExplicitAssignees() ? [] : $ids,
                allowDelegation: ! $automatic && (bool) ($step['allowDelegation'] ?? true),
                conditions: $conditions,
                timeoutHours: $timeoutHours,
                timeoutAction: $timeoutAction,
                completionRule: \App\Enums\GroupCompletion::fromStepType($stepType),
                timeoutCalendar: filled($step['timeoutCalendar'] ?? null)
                    ? \App\Enums\TimeoutCalendar::from((string) $step['timeoutCalendar'])
                    : \App\Enums\TimeoutCalendar::Elapsed,
                assigneeConfig: $assigneeConfig,
            );
        })->all();

        $data = new WorkflowTemplateData(
            name: $validated['name'],
            description: $validated['description'] !== '' ? $validated['description'] : null,
            submissionTypes: array_values(array_unique($validated['submissionTypes'])),
            isDefault: $validated['isDefault'],
            isActive: $validated['isActive'],
            steps: $stepData,
            changeSummary: $this->changeSummary !== '' ? $this->changeSummary : null,
        );

        if ($target) {
            app(UpdateWorkflowTemplateAction::class)->execute($target, $data, Auth::user());
            $message = 'Workflow template updated.';
        } else {
            app(CreateWorkflowTemplateAction::class)->execute($data, Auth::user());
            $message = 'Workflow template created.';
        }

        $this->resetForm();
        unset($this->templates);
        $this->modal('template-form')->close();
        Flux::toast($message, variant: 'success');
    }

    /** @return array<string, mixed> */
    private function blankStep(int $number): array
    {
        return [
            'name' => 'Approval Level '.$number,
            'stepType' => StepType::Sequential->value,
            'quorumCount' => null,
            'assigneeType' => AssigneeType::User->value,
            'assigneeIds' => [],
            'assigneeQuery' => '',
            'assigneeField' => '',
            'assigneeResolver' => '',
            'allowDelegation' => true,
            'conditionField' => 'priority',
            'conditionOperator' => 'equals',
            'conditionValue' => '',
            'trueStep' => null,
            'falseStep' => null,
            'timeoutHours' => null,
            'timeoutAction' => '',
            'timeoutCalendar' => 'elapsed',
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function conditionalConfiguration(array $step, int $index, int $stepCount): array
    {
        $field = trim((string) ($step['conditionField'] ?? ''));
        $operator = (string) ($step['conditionOperator'] ?? '');

        if (! in_array($field, ['type', 'title', 'priority', 'department_id', 'submitter_id', 'due_date'], true)
            && preg_match('/^metadata(?:\.[A-Za-z0-9_-]+)+$/', $field) !== 1) {
            throw ValidationException::withMessages([
                "steps.{$index}.conditionField" => __('Use a supported submission field or metadata.key path.'),
            ]);
        }

        $trueStep = filled($step['trueStep'] ?? null) ? (int) $step['trueStep'] : null;
        $falseStep = filled($step['falseStep'] ?? null) ? (int) $step['falseStep'] : null;

        foreach (['trueStep' => $trueStep, 'falseStep' => $falseStep] as $key => $target) {
            if ($target !== null && ($target <= $index + 1 || $target > $stepCount)) {
                throw ValidationException::withMessages([
                    "steps.{$index}.{$key}" => __('A branch must target a later step or completion.'),
                ]);
            }
        }

        $value = (string) ($step['conditionValue'] ?? '');
        if (in_array($operator, ['in', 'not_in'], true)) {
            $value = array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => $item !== ''));
        }

        return [
            'match' => 'all',
            'rules' => [[
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ]],
            'true_step' => $trueStep,
            'false_step' => $falseStep,
        ];
    }

    /** @param array<int, int> $ids */
    private function guardAssigneesExist(AssigneeType $type, array $ids, int $index): void
    {
        $count = match ($type) {
            AssigneeType::User => User::query()->where('is_active', true)->whereKey($ids)->count(),
            AssigneeType::Role => Role::query()->whereKey($ids)->count(),
            AssigneeType::Department => Department::query()->whereKey($ids)->count(),
            default => 0,
        };

        if ($count !== count($ids)) {
            throw ValidationException::withMessages([
                "steps.{$index}.assigneeIds" => __('One or more selected assignees are unavailable.'),
            ]);
        }
    }

    private function guardQuorumIsPossible(?int $required, int $assigneeCount, int $index): void
    {
        if ($required === null || $required > $assigneeCount) {
            throw ValidationException::withMessages([
                "steps.{$index}.quorumCount" => __("Quorum must be between 1 and {$assigneeCount} active assignees."),
            ]);
        }
    }

    /** @param array<int, int> $ids */
    private function resolvedAssigneeCount(AssigneeType $type, array $ids): int
    {
        return match ($type) {
            AssigneeType::User => User::query()->where('is_active', true)->whereKey($ids)->count(),
            AssigneeType::Role => User::query()
                ->where('is_active', true)
                ->role(Role::query()->whereKey($ids)->pluck('name')->all())
                ->count(),
            AssigneeType::Department => User::query()
                ->where('is_active', true)
                ->whereIn('department_id', $ids)
                ->count(),
            default => 0,
        };
    }

    public function publish(): void
    {
        $templateId = $this->editingTemplateId;
        $summary = $this->changeSummary !== '' ? $this->changeSummary : null;
        $this->save();

        if ($templateId === null) {
            return;
        }

        $template = WorkflowTemplate::query()->with('draftVersion')->find($templateId);

        if ($template?->draftVersion === null) {
            return;
        }

        $this->authorize('update', $template);
        app(PublishWorkflowTemplateVersionAction::class)->execute($template->draftVersion, $summary);
        unset($this->templates);
        Flux::toast('Workflow version published.', variant: 'success');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset([
            'editingTemplateId', 'editingTemplateUsed', 'editingVersionNumber', 'editingVersionStatus',
            'changeSummary', 'name', 'description', 'submissionTypes', 'isDefault', 'steps',
        ]);
        $this->isActive = true;
        $this->steps = [$this->blankStep(1)];
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

    <flux:modal name="template-form" class="md:w-[48rem]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="md">{{ $editingTemplateId ? __('Edit workflow template') : __('Add workflow template') }}</flux:heading>
                <flux:subheading>{{ __('Steps run from top to bottom. Each step may target users, roles, or a department.') }}</flux:subheading>
            </div>

            @if ($editingVersionNumber)
                <flux:callout variant="info" icon="document-duplicate" heading="{{ __('Version :number (:status)', ['number' => $editingVersionNumber, 'status' => $editingVersionStatus]) }}">
                    {{ __('Published versions stay frozen. Saving a changed chain creates a draft. Publish the draft so new requests use it. In-flight requests keep the version they started.') }}
                </flux:callout>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required />
                <div class="flex items-end gap-5 pb-2">
                    <flux:checkbox wire:model.live="isDefault" :label="__('Default template')" />
                    <flux:checkbox wire:model="isActive" :label="__('Active')" />
                </div>
                <div class="sm:col-span-2"><flux:textarea wire:model="description" :label="__('Description')" rows="2" /></div>
                <div class="sm:col-span-2"><flux:input wire:model="changeSummary" :label="__('Change summary')" :description="__('Recorded on the draft and at publish time.')" /></div>
            </div>

            @if ($isDefault)
                <p class="text-sm text-zinc-500">{{ __('The default template runs for any request type with no assigned workflow, including General Request until you assign one.') }}</p>
            @else
                <flux:checkbox.group wire:model="submissionTypes" :label="__('Legacy submission labels (optional)')" class="grid gap-2 sm:grid-cols-4">
                    @foreach (SubmissionType::cases() as $type)
                        <flux:checkbox :value="$type->value" :label="str($type->value)->replace('_', ' ')->title()" />
                    @endforeach
                </flux:checkbox.group>
                <p class="text-sm text-zinc-500">{{ __('These labels do not reserve a type. New requests follow the template assigned on the request type.') }}</p>
                <flux:error name="submissionTypes" />
            @endif

            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">{{ __('Approval steps') }}</flux:heading>
                    <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="addStep">{{ __('Add step') }}</flux:button>
                </div>
                <flux:error name="steps" />

                @foreach ($steps as $index => $step)
                    <flux:card wire:key="template-step-{{ $index }}">
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-sm font-semibold dark:bg-zinc-800">{{ $index + 1 }}</div>
                            <div class="grid flex-1 gap-4 sm:grid-cols-3">
                                <flux:input wire:model="steps.{{ $index }}.name" :label="__('Step name')" />
                                <flux:select wire:model="steps.{{ $index }}.stepType" :label="__('Step behavior')">
                                    <flux:select.option value="sequential">{{ __('Sequential — one approval') }}</flux:select.option>
                                    <flux:select.option value="parallel">{{ __('Parallel — all must approve') }}</flux:select.option>
                                    <flux:select.option value="quorum">{{ __('Quorum — N must approve') }}</flux:select.option>
                                    <flux:select.option value="conditional">{{ __('Conditional — route by submission data') }}</flux:select.option>
                                    <flux:select.option value="auto">{{ __('Automatic approval') }}</flux:select.option>
                                    <flux:select.option value="notification">{{ __('Notification') }}</flux:select.option>
                                </flux:select>
                                @if (! in_array($step['stepType'], ['conditional', 'auto', 'notification'], true))
                                    <flux:select wire:model="steps.{{ $index }}.assigneeType" wire:change="resetAssignees({{ $index }})" :label="__('Assign by')">
                                        @foreach (AssigneeType::cases() as $type)
                                            <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @else
                                    <div class="rounded-lg bg-zinc-50 p-3 text-sm text-zinc-500 dark:bg-zinc-800/60">
                                        {{ $step['stepType'] === 'notification' ? __('Sends a recorded notification and continues.') : ($step['stepType'] === 'auto' ? __('No approver is required.') : __('The system evaluates this step automatically.')) }}
                                    </div>
                                @endif

                                @if ($step['stepType'] === 'quorum')
                                    <div class="sm:col-span-3">
                                        <flux:input wire:model="steps.{{ $index }}.quorumCount" type="number" min="1" :label="__('Approvals required')" />
                                        <flux:error name="steps.{{ $index }}.quorumCount" />
                                    </div>
                                @endif

                                @if ($step['stepType'] === 'conditional')
                                    <div class="grid gap-4 sm:col-span-3 sm:grid-cols-3">
                                        <flux:input wire:model="steps.{{ $index }}.conditionField" :label="__('Field')" placeholder="priority or metadata.amount" />
                                        <flux:select wire:model="steps.{{ $index }}.conditionOperator" :label="__('Operator')">
                                            @foreach (['equals' => 'Equals', 'not_equals' => 'Does not equal', 'in' => 'In comma-separated list', 'not_in' => 'Not in list', 'gt' => 'Greater than', 'gte' => 'At least', 'lt' => 'Less than', 'lte' => 'At most', 'contains' => 'Contains', 'present' => 'Is present'] as $value => $label)
                                                <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:input wire:model="steps.{{ $index }}.conditionValue" :label="__('Value')" :disabled="$step['conditionOperator'] === 'present'" />
                                        <flux:select wire:model="steps.{{ $index }}.trueStep" :label="__('When true')">
                                            <flux:select.option value="">{{ __('Complete workflow') }}</flux:select.option>
                                            @foreach ($steps as $targetIndex => $targetStep)
                                                @if ($targetIndex > $index)<flux:select.option :value="$targetIndex + 1">{{ ($targetIndex + 1).'. '.$targetStep['name'] }}</flux:select.option>@endif
                                            @endforeach
                                        </flux:select>
                                        <flux:select wire:model="steps.{{ $index }}.falseStep" :label="__('When false')">
                                            <flux:select.option value="">{{ __('Complete workflow') }}</flux:select.option>
                                            @foreach ($steps as $targetIndex => $targetStep)
                                                @if ($targetIndex > $index)<flux:select.option :value="$targetIndex + 1">{{ ($targetIndex + 1).'. '.$targetStep['name'] }}</flux:select.option>@endif
                                            @endforeach
                                        </flux:select>
                                    </div>
                                    <flux:error name="steps.{{ $index }}.conditionField" />
                                    <flux:error name="steps.{{ $index }}.trueStep" />
                                    <flux:error name="steps.{{ $index }}.falseStep" />
                                @endif

                                @if (! in_array($step['stepType'], ['conditional', 'auto', 'notification'], true))
                                    <div class="grid gap-4 sm:col-span-3 sm:grid-cols-2">
                                        <flux:input wire:model="steps.{{ $index }}.timeoutHours" type="number" min="1" max="8760" :label="__('SLA duration (hours)')" :placeholder="__('No deadline')" />
                                        <flux:select wire:model="steps.{{ $index }}.timeoutCalendar" :label="__('Calendar')" :description="__('Business hours use the organization working days, office hours, and holidays. Elapsed hours count every hour on the clock.')">
                                            <flux:select.option value="elapsed">{{ __('Elapsed hours') }}</flux:select.option>
                                            <flux:select.option value="business">{{ __('Business hours') }}</flux:select.option>
                                        </flux:select>
                                        <flux:select wire:model="steps.{{ $index }}.timeoutAction" :label="__('When overdue')">
                                            <flux:select.option value="">{{ __('No automatic action') }}</flux:select.option>
                                            <flux:select.option value="escalate">{{ __('Escalate to next step') }}</flux:select.option>
                                            <flux:select.option value="auto_approve">{{ __('Automatically approve') }}</flux:select.option>
                                            <flux:select.option value="auto_reject">{{ __('Automatically reject') }}</flux:select.option>
                                        </flux:select>
                                        <flux:error name="steps.{{ $index }}.timeoutHours" />
                                        <flux:error name="steps.{{ $index }}.timeoutAction" />
                                    </div>

                                    <div class="sm:col-span-3">
                                        <flux:checkbox wire:model="steps.{{ $index }}.allowDelegation" :label="__('Allow assigned approvers to delegate')" />
                                    </div>

                                    <div class="sm:col-span-3">
                                        @if (in_array($step['assigneeType'], ['user', 'role', 'department'], true))
                                            <x-ui.assignee-picker
                                                :type="$step['assigneeType']"
                                                query="steps.{{ $index }}.assigneeQuery"
                                                :selected="$this->selectedAssigneesFor($index)"
                                                :results="$this->assigneeResultsFor($index)"
                                                add="addAssignee"
                                                remove="removeAssignee"
                                                :step-index="$index"
                                            />
                                            <flux:error name="steps.{{ $index }}.assigneeIds" />
                                        @elseif ($step['assigneeType'] === 'form_field')
                                            <flux:input wire:model="steps.{{ $index }}.assigneeField" :label="__('User field key')" />
                                        @elseif ($step['assigneeType'] === 'external')
                                            <flux:input wire:model="steps.{{ $index }}.assigneeResolver" :label="__('External resolver key')" />
                                        @else
                                            <p class="text-sm text-zinc-500">{{ __('Resolved when a request starts, from the requester or department manager.') }}</p>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <div class="flex shrink-0 flex-col gap-1">
                                <flux:button type="button" size="xs" variant="ghost" icon="chevron-up" wire:click="moveStep({{ $index }}, -1)" :disabled="$index === 0" />
                                <flux:button type="button" size="xs" variant="ghost" icon="chevron-down" wire:click="moveStep({{ $index }}, 1)" :disabled="$index === count($steps) - 1" />
                                <flux:button type="button" size="xs" variant="ghost" icon="trash" wire:click="removeStep({{ $index }})" :disabled="count($steps) === 1" />
                            </div>
                        </div>
                    </flux:card>
                @endforeach
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-template">{{ __('Save template') }}</flux:button>
                @if ($editingTemplateId)
                    <flux:button type="button" wire:click="publish" data-test="publish-template">{{ __('Publish version') }}</flux:button>
                @endif
            </div>
        </form>
    </flux:modal>
</div>
