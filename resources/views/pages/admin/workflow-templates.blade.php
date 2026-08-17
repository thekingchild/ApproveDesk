<?php

use App\Actions\WorkflowTemplates\CreateWorkflowTemplateAction;
use App\Actions\WorkflowTemplates\UpdateWorkflowTemplateAction;
use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\SubmissionType;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowTemplate;
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

    public string $name = '';

    public string $description = '';

    /** @var array<int, string> */
    public array $submissionTypes = [];

    public bool $isDefault = false;

    public bool $isActive = true;

    /** @var array<int, array{name: string, stepType: string, quorumCount: int|null, assigneeType: string, assigneeIds: array<int, int>}> */
    public array $steps = [];

    public function mount(): void
    {
        $this->authorize('viewAny', WorkflowTemplate::class);
    }

    #[Computed]
    public function templates(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return WorkflowTemplate::query()
            ->with('creator')
            ->withCount(['steps', 'instances'])
            ->when($this->search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(15);
    }

    #[Computed]
    public function users(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function roles(): \Illuminate\Database\Eloquent\Collection
    {
        return Role::query()->orderBy('name')->get();
    }

    #[Computed]
    public function departments(): \Illuminate\Database\Eloquent\Collection
    {
        return Department::query()->orderBy('name')->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createTemplate(): void
    {
        $this->authorize('create', WorkflowTemplate::class);
        $this->resetForm();
        $this->modal('template-form')->show();
    }

    public function editTemplate(int $templateId): void
    {
        $template = WorkflowTemplate::query()->with('steps')->withCount('instances')->findOrFail($templateId);
        $this->authorize('update', $template);

        $this->resetValidation();
        $this->editingTemplateId = $template->id;
        $this->editingTemplateUsed = $template->instances_count > 0;
        $this->name = $template->name;
        $this->description = $template->description ?? '';
        $this->submissionTypes = $template->submission_types ?? [];
        $this->isDefault = $template->is_default;
        $this->isActive = $template->is_active;
        $this->steps = $template->steps->map(fn ($step) => [
            'name' => $step->name,
            'stepType' => $step->step_type->value,
            'quorumCount' => $step->quorum_count,
            'assigneeType' => $step->assignee_type->value,
            'assigneeIds' => $step->assignee_ids,
        ])->values()->all();

        $this->modal('template-form')->show();
    }

    public function addStep(): void
    {
        if ($this->editingTemplateUsed) {
            return;
        }

        $this->steps[] = $this->blankStep(count($this->steps) + 1);
    }

    public function removeStep(int $index): void
    {
        if ($this->editingTemplateUsed || count($this->steps) <= 1) {
            return;
        }

        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function moveStep(int $index, int $direction): void
    {
        if ($this->editingTemplateUsed) {
            return;
        }

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
            ])],
            'steps.*.quorumCount' => ['nullable', 'integer', 'min:1'],
            'steps.*.assigneeType' => ['required', Rule::enum(AssigneeType::class)],
            'steps.*.assigneeIds' => ['required', 'array', 'min:1'],
            'steps.*.assigneeIds.*' => ['required', 'integer'],
        ]);

        if ($validated['isDefault'] && ! $validated['isActive']) {
            throw ValidationException::withMessages([
                'isActive' => __('The default workflow template must be active.'),
            ]);
        }

        if (! $validated['isDefault'] && $validated['submissionTypes'] === []) {
            throw ValidationException::withMessages([
                'submissionTypes' => __('Select at least one submission type or make this the default template.'),
            ]);
        }

        $stepData = collect($validated['steps'])->map(function (array $step, int $index): WorkflowTemplateStepData {
            $type = AssigneeType::from($step['assigneeType']);
            $ids = array_values(array_unique(array_map('intval', $step['assigneeIds'])));
            $this->guardAssigneesExist($type, $ids, $index);
            $assigneeCount = $this->resolvedAssigneeCount($type, $ids);

            if ($assigneeCount < 1) {
                throw ValidationException::withMessages([
                    "steps.{$index}.assigneeIds" => __('Select an assignment with at least one active user.'),
                ]);
            }

            $stepType = StepType::from($step['stepType']);
            $quorumCount = isset($step['quorumCount']) && $step['quorumCount'] !== ''
                ? (int) $step['quorumCount']
                : null;

            if ($stepType === StepType::Quorum) {
                $this->guardQuorumIsPossible($quorumCount, $assigneeCount, $index);
            }

            return new WorkflowTemplateStepData(
                name: $step['name'],
                stepType: $stepType,
                quorumCount: $stepType === StepType::Quorum ? $quorumCount : null,
                assigneeType: $type,
                assigneeIds: $ids,
            );
        })->all();

        $data = new WorkflowTemplateData(
            name: $validated['name'],
            description: $validated['description'] !== '' ? $validated['description'] : null,
            submissionTypes: array_values(array_unique($validated['submissionTypes'])),
            isDefault: $validated['isDefault'],
            isActive: $validated['isActive'],
            steps: $stepData,
        );

        if ($target) {
            app(UpdateWorkflowTemplateAction::class)->execute($target, $data);
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

    /** @return array{name: string, stepType: string, quorumCount: int|null, assigneeType: string, assigneeIds: array<int, int>} */
    private function blankStep(int $number): array
    {
        return [
            'name' => 'Approval Level '.$number,
            'stepType' => StepType::Sequential->value,
            'quorumCount' => null,
            'assigneeType' => AssigneeType::User->value,
            'assigneeIds' => [],
        ];
    }

    /** @param array<int, int> $ids */
    private function guardAssigneesExist(AssigneeType $type, array $ids, int $index): void
    {
        $count = match ($type) {
            AssigneeType::User => User::query()->where('is_active', true)->whereKey($ids)->count(),
            AssigneeType::Role => Role::query()->whereKey($ids)->count(),
            AssigneeType::Department => Department::query()->whereKey($ids)->count(),
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
        };
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset([
            'editingTemplateId', 'editingTemplateUsed', 'name', 'description',
            'submissionTypes', 'isDefault', 'steps',
        ]);
        $this->isActive = true;
        $this->steps = [$this->blankStep(1)];
    }
} ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ __('Workflow templates') }}</flux:heading>
            <flux:subheading>{{ __('Configure sequential and parallel approval chains by submission type.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="createTemplate" data-test="create-template">{{ __('Add template') }}</flux:button>
    </div>

    <div class="max-w-sm">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search templates…')" />
    </div>

    <flux:table :paginate="$this->templates">
        <flux:table.columns>
            <flux:table.column>{{ __('Template') }}</flux:table.column>
            <flux:table.column>{{ __('Covers') }}</flux:table.column>
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
                        @if ($template->is_default)
                            <flux:badge color="indigo">{{ __('Default') }}</flux:badge>
                        @else
                            {{ collect($template->submission_types)->map(fn ($type) => ucfirst($type))->implode(', ') }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell><flux:badge color="zinc">{{ $template->steps_count }}</flux:badge></flux:table.cell>
                    <flux:table.cell><flux:badge :color="$template->instances_count ? 'amber' : 'zinc'">{{ $template->instances_count }}</flux:badge></flux:table.cell>
                    <flux:table.cell><flux:badge :color="$template->is_active ? 'lime' : 'zinc'">{{ $template->is_active ? __('Active') : __('Inactive') }}</flux:badge></flux:table.cell>
                    <flux:table.cell class="text-right"><flux:button size="xs" variant="ghost" wire:click="editTemplate({{ $template->id }})">{{ __('Edit') }}</flux:button></flux:table.cell>
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

            @if ($editingTemplateUsed)
                <flux:callout variant="warning" icon="lock-closed" heading="{{ __('Approval steps are locked') }}">
                    {{ __('This template has workflow history. You may update its name, description, coverage, or active status, but not its steps.') }}
                </flux:callout>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required />
                <div class="flex items-end gap-5 pb-2">
                    <flux:checkbox wire:model.live="isDefault" :label="__('Default template')" />
                    <flux:checkbox wire:model="isActive" :label="__('Active')" />
                </div>
                <div class="sm:col-span-2"><flux:textarea wire:model="description" :label="__('Description')" rows="2" /></div>
            </div>

            @if (! $isDefault)
                <flux:checkbox.group wire:model="submissionTypes" :label="__('Submission types')" class="grid gap-2 sm:grid-cols-4">
                    @foreach (SubmissionType::cases() as $type)
                        <flux:checkbox :value="$type->value" :label="str($type->value)->replace('_', ' ')->title()" />
                    @endforeach
                </flux:checkbox.group>
                <flux:error name="submissionTypes" />
            @else
                <p class="text-sm text-zinc-500">{{ __('The default template handles types without a matching active type-specific template.') }}</p>
            @endif

            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">{{ __('Approval steps') }}</flux:heading>
                    @unless ($editingTemplateUsed)<flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="addStep">{{ __('Add step') }}</flux:button>@endunless
                </div>
                <flux:error name="steps" />

                @foreach ($steps as $index => $step)
                    <flux:card wire:key="template-step-{{ $index }}">
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-sm font-semibold dark:bg-zinc-800">{{ $index + 1 }}</div>
                            <div class="grid flex-1 gap-4 sm:grid-cols-3">
                                <flux:input wire:model="steps.{{ $index }}.name" :label="__('Step name')" :disabled="$editingTemplateUsed" />
                                <flux:select wire:model="steps.{{ $index }}.stepType" :label="__('Step behavior')" :disabled="$editingTemplateUsed">
                                    <flux:select.option value="sequential">{{ __('Sequential — one approval') }}</flux:select.option>
                                    <flux:select.option value="parallel">{{ __('Parallel — all must approve') }}</flux:select.option>
                                    <flux:select.option value="quorum">{{ __('Quorum — N must approve') }}</flux:select.option>
                                </flux:select>
                                <flux:select wire:model="steps.{{ $index }}.assigneeType" wire:change="resetAssignees({{ $index }})" :label="__('Assign by')" :disabled="$editingTemplateUsed">
                                    @foreach (AssigneeType::cases() as $type)
                                        <flux:select.option :value="$type->value">{{ str($type->value)->title() }}</flux:select.option>
                                    @endforeach
                                </flux:select>

                                @if ($step['stepType'] === 'quorum')
                                    <div class="sm:col-span-3">
                                        <flux:input wire:model="steps.{{ $index }}.quorumCount" type="number" min="1" :label="__('Approvals required')" :disabled="$editingTemplateUsed" />
                                        <flux:error name="steps.{{ $index }}.quorumCount" />
                                    </div>
                                @endif

                                <div class="sm:col-span-3">
                                    <flux:checkbox.group wire:model="steps.{{ $index }}.assigneeIds" :label="__('Assignees')" class="grid max-h-40 gap-2 overflow-y-auto sm:grid-cols-2">
                                        @foreach ($step['assigneeType'] === 'role' ? $this->roles : ($step['assigneeType'] === 'department' ? $this->departments : $this->users) as $assignee)
                                            <flux:checkbox :value="$assignee->id" :label="$assignee->name" :disabled="$editingTemplateUsed" />
                                        @endforeach
                                    </flux:checkbox.group>
                                    <flux:error name="steps.{{ $index }}.assigneeIds" />
                                </div>
                            </div>

                            @unless ($editingTemplateUsed)
                                <div class="flex shrink-0 flex-col gap-1">
                                    <flux:button type="button" size="xs" variant="ghost" icon="chevron-up" wire:click="moveStep({{ $index }}, -1)" :disabled="$index === 0" />
                                    <flux:button type="button" size="xs" variant="ghost" icon="chevron-down" wire:click="moveStep({{ $index }}, 1)" :disabled="$index === count($steps) - 1" />
                                    <flux:button type="button" size="xs" variant="ghost" icon="trash" wire:click="removeStep({{ $index }})" :disabled="count($steps) === 1" />
                                </div>
                            @endunless
                        </div>
                    </flux:card>
                @endforeach
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-template">{{ __('Save template') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
