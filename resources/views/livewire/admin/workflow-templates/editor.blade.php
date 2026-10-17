<?php

use App\Actions\WorkflowTemplates\CreateWorkflowTemplateAction;
use App\Actions\WorkflowTemplates\SaveAndPublishWorkflowTemplateAction;
use App\Actions\WorkflowTemplates\UpdateWorkflowTemplateAction;
use App\DTOs\WorkflowTemplateData;
use App\Enums\AssigneeType;
use App\Enums\SubmissionType;
use App\Livewire\Forms\StepConditionForm;
use App\Livewire\Forms\WorkflowStepsForm;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\AssigneeDirectory;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * The add/edit dialog on the Workflow templates page (ADR 0006 split, RV-ARC-06).
 * The page opens it with the open-workflow-template-form event; it announces
 * workflow-templates-changed after a save so the list refreshes. Steps are
 * loaded, checked and mapped by WorkflowStepsForm, the same as in the builder.
 */
new class extends Component {
    #[Locked]
    public ?int $editingTemplateId = null;

    public string $changeSummary = '';

    #[Locked]
    public ?int $editingVersionNumber = null;

    #[Locked]
    public ?string $editingVersionStatus = null;

    public string $name = '';

    public string $description = '';

    /** @var array<int, string> */
    public array $submissionTypes = [];

    public bool $isDefault = false;

    public bool $isActive = true;

    /** @var array<int, array<string, mixed>> */
    public array $steps = [];

    /**
     * Custom outcomes of the version being edited. This screen cannot edit them,
     * so they are carried through unchanged instead of being dropped (RV-BUG-05).
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $editingOutcomes = [];

    /** What this editor loaded; a save is refused once someone else changes the template (RV-BUG-37). */
    #[Locked]
    public ?string $editingToken = null;

    public function mount(): void
    {
        $this->authorize('viewAny', WorkflowTemplate::class);
        $this->steps = [WorkflowStepsForm::blank(1)];
    }

    #[On('open-workflow-template-form')]
    public function open(?int $templateId = null): void
    {
        $templateId === null ? $this->create() : $this->edit($templateId);
    }

    public function create(): void
    {
        $this->authorize('create', WorkflowTemplate::class);
        $this->resetForm();
        $this->modal('template-form')->show();
    }

    public function edit(int $templateId): void
    {
        $template = WorkflowTemplate::query()
            ->with(['publishedVersion.steps', 'draftVersion.steps'])
            ->findOrFail($templateId);
        $this->authorize('update', $template);

        $graph = $template->draftVersion ?? $template->publishedVersion;

        $this->resetValidation();
        $this->editingTemplateId = $template->id;
        $this->editingVersionNumber = $graph?->version;
        $this->editingVersionStatus = $graph?->status->value;
        $this->changeSummary = $graph?->change_summary ?? '';
        $this->name = $template->name;
        $this->description = $template->description ?? '';
        $this->submissionTypes = $template->submission_types ?? [];
        $this->isDefault = $template->is_default;
        $this->isActive = $template->is_active;
        $this->editingOutcomes = $graph?->outcomes ?? [];
        $this->editingToken = $template->editionToken();
        $this->steps = WorkflowStepsForm::fromVersion($graph);

        $this->modal('template-form')->show();
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

    public function addStep(): void
    {
        $this->steps[] = WorkflowStepsForm::blank(count($this->steps) + 1);
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
        if (isset($this->steps[$index])) {
            $this->steps[$index]['assigneeIds'] = [];
        }
    }

    public function save(): void
    {
        $target = $this->target();
        $this->authorize($target ? 'update' : 'create', $target ?? WorkflowTemplate::class);
        $data = $this->templateData($target);

        if ($target) {
            app(UpdateWorkflowTemplateAction::class)->execute($target, $data, Auth::user(), $this->editingToken);
            $message = 'Workflow template updated.';
        } else {
            app(CreateWorkflowTemplateAction::class)->execute($data, Auth::user());
            $message = 'Workflow template created.';
        }

        $this->saved($message);
    }

    /** Save and publish together: if publishing is refused nothing is saved (RV-ARC-07). */
    public function publish(): void
    {
        $target = $this->target();
        if ($target === null) {
            // A new template is published as soon as it is created.
            $this->save();

            return;
        }

        $this->authorize('publish', $target);
        $data = $this->templateData($target);
        app(SaveAndPublishWorkflowTemplateAction::class)->execute($target, $data, Auth::user(), $this->editingToken);

        $this->saved('Workflow version published.');
    }

    /** @return Collection<int, object> */
    protected function assigneeResultsFor(int $index): Collection
    {
        $step = $this->steps[$index] ?? [];

        return app(AssigneeDirectory::class)->search(
            (string) ($step['assigneeType'] ?? 'user'),
            (string) ($step['assigneeQuery'] ?? ''),
            $step['assigneeIds'] ?? [],
        );
    }

    /** @return Collection<int, object> */
    protected function selectedAssigneesFor(int $index): Collection
    {
        $step = $this->steps[$index] ?? [];

        return app(AssigneeDirectory::class)->selected(
            (string) ($step['assigneeType'] ?? 'user'),
            $step['assigneeIds'] ?? [],
        );
    }

    private function target(): ?WorkflowTemplate
    {
        return $this->editingTemplateId !== null
            ? WorkflowTemplate::query()->findOrFail($this->editingTemplateId)
            : null;
    }

    private function templateData(?WorkflowTemplate $target): WorkflowTemplateData
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique(WorkflowTemplate::class, 'name')->ignore($target)],
            'description' => ['nullable', 'string', 'max:5000'],
            'submissionTypes' => ['array'],
            'submissionTypes.*' => ['string', Rule::enum(SubmissionType::class)],
            'isDefault' => ['required', 'boolean'],
            'isActive' => ['required', 'boolean'],
            ...WorkflowStepsForm::rules(),
        ]);

        return new WorkflowTemplateData(
            name: $validated['name'],
            description: $validated['description'] !== '' ? $validated['description'] : null,
            submissionTypes: array_values(array_unique($validated['submissionTypes'])),
            isDefault: $validated['isDefault'],
            isActive: $validated['isActive'],
            steps: WorkflowStepsForm::toData($this->steps),
            changeSummary: $this->changeSummary !== '' ? $this->changeSummary : null,
            outcomes: $target ? $this->editingOutcomes : [],
        );
    }

    private function saved(string $message): void
    {
        $this->resetForm();
        $this->modal('template-form')->close();
        $this->dispatch('workflow-templates-changed');
        Flux::toast($message, variant: 'success');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset([
            'editingTemplateId', 'editingVersionNumber', 'editingVersionStatus',
            'changeSummary', 'name', 'description', 'submissionTypes', 'isDefault', 'steps', 'editingOutcomes', 'editingToken',
        ]);
        $this->isActive = true;
        $this->steps = [WorkflowStepsForm::blank(1)];
    }
}; ?>

<div>
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
            <flux:error name="template" />
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
                                    <flux:input wire:model="steps.{{ $index }}.conditionField" :label="__('Field')" placeholder="priority or fields.amount" />
                                    <flux:select wire:model="steps.{{ $index }}.conditionOperator" :label="__('Operator')">
                                        @foreach (StepConditionForm::OPERATORS as $value => $label)
                                            <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:input wire:model="steps.{{ $index }}.conditionValue" :label="__('Value')" :disabled="$step['conditionOperator'] === 'present'" />
                                    <flux:select wire:model="steps.{{ $index }}.trueStep" :label="__('When true')">
                                        <flux:select.option value="">{{ __('Complete workflow') }}</flux:select.option>
                                        @foreach ($steps as $targetIndex => $targetStep)
                                            @if ($targetIndex > $index)<flux:select.option :value="$targetStep['key']">{{ ($targetIndex + 1).'. '.$targetStep['name'] }}</flux:select.option>@endif
                                        @endforeach
                                    </flux:select>
                                    <flux:select wire:model="steps.{{ $index }}.falseStep" :label="__('When false')">
                                        <flux:select.option value="">{{ __('Complete workflow') }}</flux:select.option>
                                        @foreach ($steps as $targetIndex => $targetStep)
                                            @if ($targetIndex > $index)<flux:select.option :value="$targetStep['key']">{{ ($targetIndex + 1).'. '.$targetStep['name'] }}</flux:select.option>@endif
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
