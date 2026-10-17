<?php

use App\Actions\WorkflowTemplates\SaveAndPublishWorkflowTemplateAction;
use App\Actions\WorkflowTemplates\UpdateWorkflowTemplateAction;
use App\DTOs\WorkflowTemplateData;
use App\Enums\OutcomeBehavior;
use App\Enums\StepType;
use App\Livewire\Forms\StepConditionForm;
use App\Livewire\Forms\WorkflowStepsForm;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\AssigneeDirectory;
use App\Services\WorkflowTemplates\WorkflowGraphValidator;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Workflow builder. Steps are loaded, checked and mapped by WorkflowStepsForm,
 * the same as on the Workflow templates page (RV-ARC-06). The simulation and
 * versions cards are child components (ADR 0006 split).
 */
new #[Title('Workflow builder')] class extends Component {
    #[Locked]
    public int $templateId;

    public string $name = '';

    public string $description = '';

    public string $changeSummary = '';

    public bool $isDefault = false;

    public bool $isActive = true;

    /** @var array<int, array<string, mixed>> */
    public array $steps = [];

    public string $viewMode = 'canvas';

    public string $assigneeQuery = '';

    public ?int $selectedIndex = 0;

    public string $connectMode = '';

    public float $zoom = 1;

    /** @var list<string> */
    public array $history = [];

    public int $historyIndex = -1;

    public bool $confirmingPublish = false;

    /** @var list<array{key: string, label: string, behavior: string, requires_comment: bool}> */
    public array $outcomes = [];

    /** What this editor loaded; a save is refused once someone else changes the template (RV-BUG-37). */
    #[Locked]
    public string $editionToken = '';

    public function mount(WorkflowTemplate|int $template): void
    {
        $model = $template instanceof WorkflowTemplate ? $template : WorkflowTemplate::query()->findOrFail($template);
        $this->authorize('update', $model);
        $this->templateId = $model->id;
        $this->hydrateFrom($model);
        $this->pushHistory();
    }

    /** A new step type starts with that type's completion rule; a chosen rule belongs to the current type. */
    public function updatedSteps(mixed $value, ?string $key = null): void
    {
        [$index, $field] = array_pad(explode('.', (string) $key, 2), 2, null);
        if (! isset($this->steps[(int) $index])) {
            return;
        }

        if ($field === 'stepType') {
            $this->steps[(int) $index] = WorkflowStepsForm::retyped($this->steps[(int) $index]);
        } elseif ($field === 'completionRule') {
            $this->steps[(int) $index]['completionRuleStepType'] = $this->steps[(int) $index]['stepType'] ?? null;
        }
    }

    public function addAssignee(int $id): void
    {
        if ($this->selectedIndex === null || ! isset($this->steps[$this->selectedIndex])) {
            return;
        }
        $ids = array_map('intval', $this->steps[$this->selectedIndex]['assigneeIds'] ?? []);
        $ids[] = $id;
        $this->steps[$this->selectedIndex]['assigneeIds'] = array_values(array_unique($ids));
        $this->assigneeQuery = '';
        $this->pushHistory();
    }

    public function removeAssignee(int $id): void
    {
        if ($this->selectedIndex === null || ! isset($this->steps[$this->selectedIndex])) {
            return;
        }
        $this->steps[$this->selectedIndex]['assigneeIds'] = array_values(array_filter(
            array_map('intval', $this->steps[$this->selectedIndex]['assigneeIds'] ?? []),
            fn (int $assigned): bool => $assigned !== $id,
        ));
        $this->pushHistory();
    }

    public function addNode(string $kind): void
    {
        [$type, $name] = match ($kind) {
            'condition' => [StepType::Conditional, 'Condition'],
            'automatic' => [StepType::Auto, 'Automatic action'],
            'notification' => [StepType::Notification, 'Notify stakeholders'],
            default => [StepType::Sequential, 'Approval'],
        };
        $this->steps[] = WorkflowStepsForm::blank(count($this->steps) + 1, $type, $name);
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
        $copy['key'] = StepConditionForm::newKey();
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
            $this->steps[$this->selectedIndex][$field] = $this->steps[$index]['key'];
            $this->connectMode = '';
            $this->pushHistory();

            return;
        }

        $this->selectedIndex = $index;
        $this->assigneeQuery = '';
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
        $this->authorize('publish', $this->template());
        $model = $this->persist();
        $draft = $model->draftVersion ?? $model->publishedVersion;
        if ($draft === null) {
            return;
        }
        app(WorkflowGraphValidator::class)->validate($draft->steps()->get());
        $this->confirmingPublish = true;
        $this->modal('publish-workflow')->show();
    }

    /** Save and publish together: if publishing is refused nothing is saved (RV-ARC-07). */
    public function confirmPublish(): void
    {
        $model = $this->template();
        $this->authorize('publish', $model);
        app(SaveAndPublishWorkflowTemplateAction::class)->execute($model, $this->templateData($model), Auth::user(), $this->editionToken);

        $this->confirmingPublish = false;
        $this->modal('publish-workflow')->close();
        $this->hydrateFrom($this->template());
        $this->dispatch('workflow-versions-changed');
        Flux::toast('Workflow version published.', variant: 'success');
    }

    #[On('workflow-version-restored')]
    public function reloadDraft(): void
    {
        $this->authorize('update', $this->template());
        $this->hydrateFrom($this->template());
        $this->pushHistory();
    }

    public function addOutcome(): void
    {
        $this->outcomes[] = [
            'key' => 'custom_'.(count($this->outcomes) + 1),
            'label' => 'Approve with conditions',
            'behavior' => OutcomeBehavior::Approve->value,
            'requires_comment' => true,
        ];
    }

    public function removeOutcome(int $index): void
    {
        unset($this->outcomes[$index]);
        $this->outcomes = array_values($this->outcomes);
    }

    protected function template(): WorkflowTemplate
    {
        return WorkflowTemplate::query()
            ->with(['publishedVersion.steps', 'draftVersion.steps'])
            ->findOrFail($this->templateId);
    }

    /** @return Collection<int, object> */
    protected function assigneeResults(): Collection
    {
        $step = $this->selectedIndex !== null ? ($this->steps[$this->selectedIndex] ?? []) : [];

        return app(AssigneeDirectory::class)->search(
            (string) ($step['assigneeType'] ?? 'user'),
            $this->assigneeQuery,
            $step['assigneeIds'] ?? [],
        );
    }

    /** @return Collection<int, object> */
    protected function selectedAssignees(): Collection
    {
        $step = $this->selectedIndex !== null ? ($this->steps[$this->selectedIndex] ?? []) : [];

        return app(AssigneeDirectory::class)->selected(
            (string) ($step['assigneeType'] ?? 'user'),
            $step['assigneeIds'] ?? [],
        );
    }

    private function persist(): WorkflowTemplate
    {
        $model = $this->template();
        $this->authorize('update', $model);
        app(UpdateWorkflowTemplateAction::class)->execute($model, $this->templateData($model), Auth::user(), $this->editionToken);
        $model = $this->template();
        $this->editionToken = $model->editionToken();
        $this->dispatch('workflow-versions-changed');

        return $model;
    }

    private function templateData(WorkflowTemplate $model): WorkflowTemplateData
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique(WorkflowTemplate::class, 'name')->ignore($this->templateId)],
            ...WorkflowStepsForm::rules(),
        ]);

        return new WorkflowTemplateData(
            name: $this->name,
            description: $this->description !== '' ? $this->description : null,
            submissionTypes: $model->submission_types ?? [],
            isDefault: $this->isDefault,
            isActive: $this->isActive,
            steps: WorkflowStepsForm::toData($this->steps),
            changeSummary: $this->changeSummary !== '' ? $this->changeSummary : null,
            outcomes: $this->normalizedOutcomes(),
        );
    }

    private function hydrateFrom(WorkflowTemplate $model): void
    {
        $graph = $model->draftVersion ?? $model->publishedVersion;
        $this->editionToken = $model->editionToken();
        $this->name = $model->name;
        $this->description = $model->description ?? '';
        $this->isDefault = $model->is_default;
        $this->isActive = $model->is_active;
        $this->changeSummary = $graph?->change_summary ?? '';
        $this->outcomes = collect($graph?->outcomes ?? [])->map(fn (array $row): array => [
            'key' => (string) ($row['key'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'behavior' => (string) ($row['behavior'] ?? OutcomeBehavior::Approve->value),
            'requires_comment' => (bool) ($row['requires_comment'] ?? true),
        ])->values()->all();
        $this->steps = WorkflowStepsForm::fromVersion($graph) ?: [WorkflowStepsForm::blank(1)];
        $this->selectedIndex = 0;
    }

    /** @return list<array{key: string, label: string, behavior: string, requires_comment: bool}> */
    private function normalizedOutcomes(): array
    {
        return collect($this->outcomes)
            ->filter(fn (array $row): bool => filled($row['key'] ?? null) && filled($row['label'] ?? null))
            ->map(fn (array $row): array => [
                'key' => (string) $row['key'],
                'label' => (string) $row['label'],
                'behavior' => (string) ($row['behavior'] ?? OutcomeBehavior::Approve->value),
                'requires_comment' => (bool) ($row['requires_comment'] ?? true),
            ])
            ->values()
            ->all();
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

<div class="space-y-4" wire:keydown.ctrl.z.prevent="undo" wire:keydown.ctrl.y.prevent="redo" wire:keydown.ctrl.shift.z.prevent="redo">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Workflow templates'), 'url' => route('admin.workflow-templates.index')], ['label' => $name]]" :back="route('admin.workflow-templates.index')" back-label="Back to templates" />

    <x-ui.page-header :title="$name" :description="__('Canvas and list views of the same chain. Start and end are implied. Publish to freeze a version.')" help-article="workflow-templates">
        <x-slot:actions>
            <flux:button variant="ghost" wire:click="saveDraft" data-test="builder-save">{{ __('Save draft') }}</flux:button>
            <flux:button variant="primary" wire:click="requestPublish" data-test="builder-publish">{{ __('Publish version') }}</flux:button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('This workflow cannot be saved yet')" data-test="builder-errors">
            <flux:callout.text>
                <ul class="list-disc space-y-1 ps-5">
                    @foreach ($errors->getMessages() as $key => $messages)
                        @php $stepNumber = preg_match('/^steps\.(\d+)/', $key, $match) === 1 ? (int) $match[1] : null; @endphp
                        <li>
                            @if ($stepNumber !== null && isset($steps[$stepNumber]))
                                <button type="button" class="font-medium underline" wire:click="selectStep({{ $stepNumber }})">{{ ($stepNumber + 1).'. '.$steps[$stepNumber]['name'] }}</button>:
                            @endif
                            {{ $messages[0] }}
                        </li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

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
            <x-workflow-builder.step-map :steps="$steps" :selected-index="$selectedIndex" :view-mode="$viewMode" :zoom="$zoom" />
        </div>

        <aside class="space-y-4">
            @if ($selectedIndex !== null && isset($steps[$selectedIndex]))
                <x-workflow-builder.step-inspector
                    :steps="$steps"
                    :index="$selectedIndex"
                    :connect-mode="$connectMode"
                    :selected-assignees="$this->selectedAssignees()"
                    :assignee-results="$this->assigneeResults()"
                />
            @endif

            <flux:card>
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">{{ __('Custom outcomes') }}</flux:heading>
                    <flux:button size="xs" wire:click="addOutcome" data-test="add-outcome">{{ __('Add') }}</flux:button>
                </div>
                <p class="mt-2 text-xs text-zinc-500">{{ __('Approve, reject, return, request information, escalate, and approve with conditions are always available. Extra outcomes map to those same engine behaviors.') }}</p>
                <div class="mt-3 space-y-3">
                    @foreach ($outcomes as $index => $outcome)
                        <div class="grid gap-2" wire:key="outcome-{{ $index }}">
                            <flux:input wire:model="outcomes.{{ $index }}.label" :label="__('Label')" />
                            <flux:select wire:model="outcomes.{{ $index }}.behavior" :label="__('Engine behavior')">
                                @foreach (OutcomeBehavior::cases() as $behavior)
                                    <flux:select.option :value="$behavior->value">{{ str($behavior->value)->replace('_', ' ')->title() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:checkbox wire:model="outcomes.{{ $index }}.requires_comment" :label="__('Comment required')" />
                            <flux:button size="xs" variant="danger" wire:click="removeOutcome({{ $index }})">{{ __('Remove') }}</flux:button>
                        </div>
                    @endforeach
                </div>
            </flux:card>

            <livewire:admin.workflow-builder.simulation :template-id="$templateId" :steps="$steps" wire:key="workflow-simulation" />

            <livewire:admin.workflow-builder.versions :template-id="$templateId" wire:key="workflow-versions" />
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
