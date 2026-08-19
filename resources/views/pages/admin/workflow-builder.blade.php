<?php

use App\Actions\WorkflowTemplates\PublishWorkflowTemplateVersionAction;
use App\Actions\WorkflowTemplates\RestoreWorkflowTemplateVersionAction;
use App\Actions\WorkflowTemplates\UpdateWorkflowTemplateAction;
use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\TimeoutAction;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateVersion;
use App\Services\WorkflowTemplates\WorkflowGraphSimulator;
use App\Services\WorkflowTemplates\WorkflowGraphValidator;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Title('Workflow builder')] class extends Component {
    public int $templateId;

    public string $name = '';

    public string $description = '';

    public string $changeSummary = '';

    public bool $isDefault = false;

    public bool $isActive = true;

    /** @var array<int, array<string, mixed>> */
    public array $steps = [];

    public string $viewMode = 'canvas';

    public ?int $selectedIndex = 0;

    public string $connectMode = '';

    public float $zoom = 1;

    /** @var list<string> */
    public array $history = [];

    public int $historyIndex = -1;

    public string $sampleTitle = 'Sample expense request';

    public string $samplePriority = 'high';

    public string $sampleMetadata = '{"amount": 15000}';

    /** @var list<array{order: int, name: string, kind: string, detail: string}> */
    public array $simulationTrace = [];

    public ?int $compareFrom = null;

    public ?int $compareTo = null;

    public bool $confirmingPublish = false;

    public function mount(WorkflowTemplate|int $template): void
    {
        $model = $template instanceof WorkflowTemplate ? $template : WorkflowTemplate::query()->findOrFail($template);
        $this->authorize('update', $model);
        $this->templateId = $model->id;
        $this->hydrateFrom($model);
        $this->pushHistory();
    }

    public function model(): WorkflowTemplate
    {
        return WorkflowTemplate::query()
            ->with(['publishedVersion.steps', 'draftVersion.steps', 'versions.steps', 'versions.creator'])
            ->findOrFail($this->templateId);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    #[Computed]
    public function users(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Role> */
    #[Computed]
    public function roles(): \Illuminate\Database\Eloquent\Collection
    {
        return Role::query()->orderBy('name')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Department> */
    #[Computed]
    public function departments(): \Illuminate\Database\Eloquent\Collection
    {
        return Department::query()->orderBy('name')->get();
    }

    public function addNode(string $kind): void
    {
        $type = match ($kind) {
            'condition' => StepType::Conditional->value,
            'automatic' => StepType::Auto->value,
            'notification' => StepType::Notification->value,
            default => StepType::Sequential->value,
        };
        $step = $this->blankStep(count($this->steps) + 1);
        $step['stepType'] = $type;
        $step['name'] = match ($kind) {
            'condition' => 'Condition',
            'automatic' => 'Automatic action',
            'notification' => 'Notify stakeholders',
            default => 'Approval',
        };
        $this->steps[] = $step;
        $this->selectedIndex = count($this->steps) - 1;
        $this->pushHistory();
    }

    public function duplicateSelected(): void
    {
        if ($this->selectedIndex === null || ! isset($this->steps[$this->selectedIndex])) {
            return;
        }
        $copy = $this->steps[$this->selectedIndex];
        $copy['name'] .= ' copy';
        $insert = $this->selectedIndex + 1;
        array_splice($this->steps, $insert, 0, [$copy]);
        $this->selectedIndex = $insert;
        $this->pushHistory();
    }

    public function deleteSelected(): void
    {
        if ($this->selectedIndex === null || count($this->steps) <= 1 || ! isset($this->steps[$this->selectedIndex])) {
            return;
        }
        unset($this->steps[$this->selectedIndex]);
        $this->steps = array_values($this->steps);
        $this->selectedIndex = min($this->selectedIndex, count($this->steps) - 1);
        $this->pushHistory();
    }

    public function moveStep(int $index, int $direction): void
    {
        $this->selectedIndex = $index;
        $this->moveSelected($direction);
    }

    public function moveSelected(int $direction): void
    {
        if ($this->selectedIndex === null) {
            return;
        }
        $destination = $this->selectedIndex + $direction;
        if (! isset($this->steps[$this->selectedIndex], $this->steps[$destination])) {
            return;
        }
        [$this->steps[$this->selectedIndex], $this->steps[$destination]] = [$this->steps[$destination], $this->steps[$this->selectedIndex]];
        $this->selectedIndex = $destination;
        $this->pushHistory();
    }

    public function selectStep(int $index): void
    {
        if ($this->connectMode !== '' && $this->selectedIndex !== null && $this->selectedIndex !== $index) {
            $field = $this->connectMode === 'false' ? 'falseStep' : 'trueStep';
            $this->steps[$this->selectedIndex][$field] = $index + 1;
            $this->connectMode = '';
            $this->pushHistory();

            return;
        }

        $this->selectedIndex = $index;
    }

    public function beginConnect(string $mode): void
    {
        $this->connectMode = $mode;
    }

    public function undo(): void
    {
        if ($this->historyIndex < 1) {
            return;
        }
        $this->historyIndex--;
        $this->steps = json_decode($this->history[$this->historyIndex], true, 512, JSON_THROW_ON_ERROR);
        $this->selectedIndex = 0;
    }

    public function redo(): void
    {
        if ($this->historyIndex >= count($this->history) - 1) {
            return;
        }
        $this->historyIndex++;
        $this->steps = json_decode($this->history[$this->historyIndex], true, 512, JSON_THROW_ON_ERROR);
        $this->selectedIndex = 0;
    }

    public function zoomIn(): void
    {
        $this->zoom = min(1.6, round($this->zoom + 0.1, 2));
    }

    public function zoomOut(): void
    {
        $this->zoom = max(0.6, round($this->zoom - 0.1, 2));
    }

    public function fitCanvas(): void
    {
        $this->zoom = 1;
    }

    public function saveDraft(): void
    {
        $this->persist();
        Flux::toast('Draft saved.', variant: 'success');
    }

    public function requestPublish(): void
    {
        $this->persist();
        $draft = $this->model()->draftVersion ?? $this->model()->publishedVersion;
        if ($draft === null) {
            return;
        }
        app(WorkflowGraphValidator::class)->validate($draft->steps()->get());
        $this->confirmingPublish = true;
        $this->modal('publish-workflow')->show();
    }

    public function confirmPublish(): void
    {
        $this->persist();
        $draft = $this->model()->draftVersion;
        if ($draft !== null) {
            app(PublishWorkflowTemplateVersionAction::class)->execute($draft, $this->changeSummary !== '' ? $this->changeSummary : null);
        }
        $this->confirmingPublish = false;
        $this->modal('publish-workflow')->close();
        $this->hydrateFrom($this->model());
        Flux::toast('Workflow version published.', variant: 'success');
    }

    public function simulate(): void
    {
        $this->persist();
        $graph = $this->model()->draftVersion ?? $this->model()->publishedVersion;
        $metadata = json_decode($this->sampleMetadata, true);
        $this->simulationTrace = app(WorkflowGraphSimulator::class)->trace(
            $graph?->steps ?? collect(),
            [
                'title' => $this->sampleTitle,
                'priority' => $this->samplePriority,
                'metadata' => is_array($metadata) ? $metadata : [],
            ],
        );
    }

    public function restoreVersion(int $versionId): void
    {
        $version = WorkflowTemplateVersion::query()->findOrFail($versionId);
        abort_unless($version->workflow_template_id === $this->templateId, 404);
        app(RestoreWorkflowTemplateVersionAction::class)->execute($version, Auth::user());
        $this->hydrateFrom($this->model());
        $this->pushHistory();
        Flux::toast('Version restored as a draft.', variant: 'success');
    }

    private function persist(): WorkflowTemplate
    {
        $model = $this->model();
        $this->authorize('update', $model);
        $data = new WorkflowTemplateData(
            name: $this->name,
            description: $this->description !== '' ? $this->description : null,
            submissionTypes: $model->submission_types ?? [],
            isDefault: $this->isDefault,
            isActive: $this->isActive,
            steps: $this->stepData(),
            changeSummary: $this->changeSummary !== '' ? $this->changeSummary : null,
        );
        app(UpdateWorkflowTemplateAction::class)->execute($model, $data, Auth::user());

        return $this->model();
    }

    /** @return list<WorkflowTemplateStepData> */
    private function stepData(): array
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique(WorkflowTemplate::class, 'name')->ignore($this->templateId)],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.name' => ['required', 'string', 'max:255'],
            'steps.*.stepType' => ['required', Rule::enum(StepType::class)],
        ]);

        return collect($this->steps)->map(function (array $step, int $index): WorkflowTemplateStepData {
            $stepType = StepType::from($step['stepType']);
            $type = AssigneeType::from($step['assigneeType'] ?? AssigneeType::User->value);
            $ids = array_values(array_unique(array_map('intval', $step['assigneeIds'] ?? [])));
            $automatic = $stepType->isAutomatic();

            if (! $automatic && $ids === []) {
                throw ValidationException::withMessages([
                    "steps.{$index}.assigneeIds" => __('Select an assignment with at least one active user.'),
                ]);
            }

            $conditions = $stepType === StepType::Conditional ? [
                'match' => 'all',
                'rules' => [[
                    'field' => $step['conditionField'] ?: 'priority',
                    'operator' => $step['conditionOperator'] ?: 'equals',
                    'value' => $step['conditionValue'] ?? '',
                ]],
                'true_step' => filled($step['trueStep'] ?? null) ? (int) $step['trueStep'] : null,
                'false_step' => filled($step['falseStep'] ?? null) ? (int) $step['falseStep'] : null,
            ] : null;

            return new WorkflowTemplateStepData(
                name: $step['name'],
                stepType: $stepType,
                quorumCount: $stepType === StepType::Quorum ? (int) ($step['quorumCount'] ?: 1) : null,
                assigneeType: $type,
                assigneeIds: $automatic ? [] : $ids,
                allowDelegation: ! $automatic && (bool) ($step['allowDelegation'] ?? true),
                conditions: $conditions,
                timeoutHours: filled($step['timeoutHours'] ?? null) ? (int) $step['timeoutHours'] : null,
                timeoutAction: filled($step['timeoutAction'] ?? null) ? TimeoutAction::from($step['timeoutAction']) : null,
            );
        })->all();
    }

    private function hydrateFrom(WorkflowTemplate $model): void
    {
        $graph = $model->draftVersion ?? $model->publishedVersion;
        $this->name = $model->name;
        $this->description = $model->description ?? '';
        $this->isDefault = $model->is_default;
        $this->isActive = $model->is_active;
        $this->changeSummary = $graph?->change_summary ?? '';
        $this->steps = ($graph?->steps ?? collect())->map(fn ($step) => [
            'name' => $step->name,
            'stepType' => $step->step_type->value,
            'quorumCount' => $step->quorum_count,
            'assigneeType' => $step->assignee_type->value,
            'assigneeIds' => $step->assignee_ids,
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
        ])->values()->all();
        if ($this->steps === []) {
            $this->steps = [$this->blankStep(1)];
        }
        $this->selectedIndex = 0;
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
            'allowDelegation' => true,
            'conditionField' => 'priority',
            'conditionOperator' => 'equals',
            'conditionValue' => '',
            'trueStep' => null,
            'falseStep' => null,
            'timeoutHours' => null,
            'timeoutAction' => '',
        ];
    }

    private function pushHistory(): void
    {
        $snapshot = json_encode($this->steps, JSON_THROW_ON_ERROR);
        $this->history = array_slice($this->history, 0, $this->historyIndex + 1);
        $this->history[] = $snapshot;
        $this->historyIndex = count($this->history) - 1;
        if (count($this->history) > 40) {
            array_shift($this->history);
            $this->historyIndex--;
        }
    }
};
?>

@php
    $model = $this->model();
    $nodeHeight = 108;
    $canvasHeight = 140 + (count($steps) * $nodeHeight);
    $selected = $selectedIndex !== null ? ($steps[$selectedIndex] ?? null) : null;
@endphp

<div class="space-y-4" wire:keydown.ctrl.z.prevent="undo" wire:keydown.ctrl.y.prevent="redo" wire:keydown.ctrl.shift.z.prevent="redo">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Workflow templates'), 'url' => route('admin.workflow-templates.index')], ['label' => $name]]" :back="route('admin.workflow-templates.index')" back-label="Back to templates" />

    <x-ui.page-header :title="$name" :description="__('Canvas and list views of the same chain. Start and end are implied. Publish to freeze a version.')" help-article="workflow-templates">
        <x-slot:actions>
            <flux:button variant="ghost" wire:click="saveDraft" data-test="builder-save">{{ __('Save draft') }}</flux:button>
            <flux:button variant="primary" wire:click="requestPublish" data-test="builder-publish">{{ __('Publish version') }}</flux:button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-2">
        <flux:button size="sm" :variant="$viewMode === 'canvas' ? 'primary' : 'ghost'" wire:click="$set('viewMode', 'canvas')" data-test="view-canvas">{{ __('Canvas') }}</flux:button>
        <flux:button size="sm" :variant="$viewMode === 'list' ? 'primary' : 'ghost'" wire:click="$set('viewMode', 'list')" data-test="view-list">{{ __('List') }}</flux:button>
        <flux:separator vertical class="hidden sm:block" />
        <flux:button size="sm" wire:click="addNode('approval')" data-test="add-approval">{{ __('Approval') }}</flux:button>
        <flux:button size="sm" wire:click="addNode('condition')" data-test="add-condition">{{ __('Condition') }}</flux:button>
        <flux:button size="sm" wire:click="addNode('automatic')">{{ __('Automatic') }}</flux:button>
        <flux:button size="sm" wire:click="addNode('notification')" data-test="add-notification">{{ __('Notification') }}</flux:button>
        <flux:button size="sm" wire:click="undo" :disabled="$historyIndex < 1">{{ __('Undo') }}</flux:button>
        <flux:button size="sm" wire:click="redo" :disabled="$historyIndex >= count($history) - 1">{{ __('Redo') }}</flux:button>
        <flux:button size="sm" wire:click="zoomOut">−</flux:button>
        <span class="text-sm text-zinc-500">{{ (int) round($zoom * 100) }}%</span>
        <flux:button size="sm" wire:click="zoomIn">+</flux:button>
        <flux:button size="sm" wire:click="fitCanvas">{{ __('Fit') }}</flux:button>
    </div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-4">
            @if ($viewMode === 'canvas')
                <div class="overflow-auto rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950" data-test="workflow-canvas">
                    <div class="ad-workflow-canvas origin-top-left p-6" style="transform: scale({{ $zoom }}); width: 28rem; height: {{ $canvasHeight }}px;">
                        <div class="ad-workflow-node ad-workflow-node--start">{{ __('Start') }}</div>
                        @foreach ($steps as $index => $step)
                            @php $kind = \App\Enums\StepType::from($step['stepType'])->canvasKind(); @endphp
                            <button
                                type="button"
                                wire:click="selectStep({{ $index }})"
                                @class(['ad-workflow-node', 'ad-workflow-node--'.$kind, 'ad-workflow-node--selected' => $selectedIndex === $index])
                                data-test="canvas-node-{{ $index }}"
                            >
                                <span class="text-xs uppercase tracking-wide text-zinc-500">{{ $kind }}</span>
                                <span class="block font-semibold">{{ $step['name'] }}</span>
                            </button>
                        @endforeach
                        <div class="ad-workflow-node ad-workflow-node--end">{{ __('End') }}</div>
                    </div>
                </div>
            @else
                <ol class="space-y-2" aria-label="{{ __('Workflow steps') }}" data-test="workflow-list">
                    @foreach ($steps as $index => $step)
                        <li>
                            <div @class(['flex items-center gap-2 rounded-xl border p-3', 'border-blue-500' => $selectedIndex === $index, 'border-zinc-200 dark:border-zinc-700' => $selectedIndex !== $index])>
                                <button type="button" class="flex-1 text-left" wire:click="selectStep({{ $index }})" data-test="list-node-{{ $index }}">
                                    <span class="font-semibold">{{ $index + 1 }}. {{ $step['name'] }}</span>
                                    <span class="ml-2 text-sm text-zinc-500">{{ $step['stepType'] }}</span>
                                </button>
                                <flux:button size="xs" wire:click="moveStep({{ $index }}, -1)" :disabled="$index === 0" aria-label="Move up">↑</flux:button>
                                <flux:button size="xs" wire:click="moveStep({{ $index }}, 1)" :disabled="$index === count($steps) - 1" aria-label="Move down">↓</flux:button>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ($simulationTrace !== [])
                <flux:card data-test="simulation-trace">
                    <flux:heading size="sm">{{ __('Simulation trace') }}</flux:heading>
                    <ol class="mt-3 space-y-2">
                        @foreach ($simulationTrace as $entry)
                            <li><span class="font-semibold">{{ $entry['name'] }}</span> — {{ $entry['detail'] }}</li>
                        @endforeach
                    </ol>
                </flux:card>
            @endif
        </div>

        <aside class="space-y-4">
            @if ($selected)
                <flux:card>
                    <flux:heading size="sm">{{ __('Selected node') }}</flux:heading>
                    <div class="mt-3 space-y-3">
                        <flux:input wire:model.blur="steps.{{ $selectedIndex }}.name" :label="__('Name')" />
                        <flux:select wire:model.live="steps.{{ $selectedIndex }}.stepType" :label="__('Type')">
                            <flux:select.option value="sequential">{{ __('Approval · sequential') }}</flux:select.option>
                            <flux:select.option value="parallel">{{ __('Approval · parallel') }}</flux:select.option>
                            <flux:select.option value="quorum">{{ __('Approval · quorum') }}</flux:select.option>
                            <flux:select.option value="conditional">{{ __('Condition') }}</flux:select.option>
                            <flux:select.option value="auto">{{ __('Automatic action') }}</flux:select.option>
                            <flux:select.option value="notification">{{ __('Notification') }}</flux:select.option>
                        </flux:select>
                        @if (! in_array($selected['stepType'], ['conditional', 'auto', 'notification'], true))
                            <flux:select wire:model="steps.{{ $selectedIndex }}.assigneeType" :label="__('Assign by')">
                                @foreach (AssigneeType::cases() as $type)
                                    <flux:select.option :value="$type->value">{{ str($type->value)->title() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <div class="max-h-40 space-y-1 overflow-auto">
                                @foreach ($selected['assigneeType'] === 'role' ? $this->roles : ($selected['assigneeType'] === 'department' ? $this->departments : $this->users) as $assignee)
                                    <flux:checkbox wire:model="steps.{{ $selectedIndex }}.assigneeIds" :value="$assignee->id" :label="$assignee->name" />
                                @endforeach
                            </div>
                        @endif
                        @if ($selected['stepType'] === 'conditional')
                            <flux:input wire:model="steps.{{ $selectedIndex }}.conditionField" :label="__('Field')" />
                            <flux:input wire:model="steps.{{ $selectedIndex }}.conditionValue" :label="__('Value')" />
                            <flux:button size="sm" wire:click="beginConnect('true')" data-test="connect-true">{{ $connectMode === 'true' ? __('Click a later node') : __('Connect true branch') }}</flux:button>
                            <flux:button size="sm" wire:click="beginConnect('false')">{{ $connectMode === 'false' ? __('Click a later node') : __('Connect false branch') }}</flux:button>
                            <p class="text-xs text-zinc-500">{{ __('True → step :true · False → step :false', ['true' => $selected['trueStep'] ?: __('end'), 'false' => $selected['falseStep'] ?: __('end')]) }}</p>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" wire:click="duplicateSelected">{{ __('Duplicate') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="deleteSelected">{{ __('Delete') }}</flux:button>
                        </div>
                    </div>
                </flux:card>
            @endif

            <flux:card>
                <flux:heading size="sm">{{ __('Simulation') }}</flux:heading>
                <div class="mt-3 space-y-3">
                    <flux:input wire:model="sampleTitle" :label="__('Sample title')" />
                    <flux:select wire:model="samplePriority" :label="__('Priority')">
                        <flux:select.option value="low">{{ __('Low') }}</flux:select.option>
                        <flux:select.option value="normal">{{ __('Normal') }}</flux:select.option>
                        <flux:select.option value="high">{{ __('High') }}</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="sampleMetadata" :label="__('Metadata JSON')" />
                    <flux:button size="sm" wire:click="simulate" data-test="run-simulation">{{ __('Run simulation') }}</flux:button>
                </div>
            </flux:card>

            <flux:card>
                <flux:heading size="sm">{{ __('Versions') }}</flux:heading>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($model->versions as $version)
                        <li class="flex items-center justify-between gap-2">
                            <span>v{{ $version->version }} · {{ $version->status->value }}</span>
                            <flux:button size="xs" wire:click="restoreVersion({{ $version->id }})" data-test="restore-version-{{ $version->id }}">{{ __('Restore draft') }}</flux:button>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <flux:select wire:model="compareFrom" :label="__('Compare from')">
                        <flux:select.option value="">{{ __('Version') }}</flux:select.option>
                        @foreach ($model->versions as $version)
                            <flux:select.option :value="$version->id">v{{ $version->version }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="compareTo" :label="__('Compare to')">
                        <flux:select.option value="">{{ __('Version') }}</flux:select.option>
                        @foreach ($model->versions as $version)
                            <flux:select.option :value="$version->id">v{{ $version->version }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @if ($compareFrom && $compareTo)
                    @php
                        $from = $model->versions->firstWhere('id', (int) $compareFrom);
                        $to = $model->versions->firstWhere('id', (int) $compareTo);
                    @endphp
                    @if ($from && $to)
                        <dl class="mt-3 space-y-1 text-sm" data-test="version-compare">
                            <div>{{ __('From hash') }}: {{ str($from->schema_hash)->limit(12) }}</div>
                            <div>{{ __('To hash') }}: {{ str($to->schema_hash)->limit(12) }}</div>
                            <div>{{ __('From steps') }}: {{ $from->steps->pluck('name')->implode(', ') }}</div>
                            <div>{{ __('To steps') }}: {{ $to->steps->pluck('name')->implode(', ') }}</div>
                        </dl>
                    @endif
                @endif
            </flux:card>
        </aside>
    </div>

    <flux:input wire:model="changeSummary" :label="__('Change summary')" />

    <flux:modal name="publish-workflow" class="max-w-lg">
        <flux:heading size="md">{{ __('Publish this version?') }}</flux:heading>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ __('New requests will use this chain. Requests already in flight keep their current version.') }}</p>
        <div class="mt-4 flex justify-end gap-2">
            <flux:button wire:click="$set('confirmingPublish', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" wire:click="confirmPublish" data-test="confirm-publish">{{ __('Publish') }}</flux:button>
        </div>
    </flux:modal>
</div>
