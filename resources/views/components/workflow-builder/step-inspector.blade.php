{{-- The selected step's settings in the builder (ADR 0006 split). Rendered inside the builder, so wire: bindings reach it. --}}
@props(['steps' => [], 'index' => 0, 'connectMode' => '', 'selectedAssignees' => collect(), 'assigneeResults' => collect()])

@php $selected = $steps[$index]; @endphp

<flux:card wire:key="step-inspector-{{ $index }}">
    <flux:heading size="sm">{{ __('Selected node') }}</flux:heading>
    <div class="mt-3 space-y-3">
        <flux:input wire:model.blur="steps.{{ $index }}.name" :label="__('Name')" />
        <flux:select wire:model.live="steps.{{ $index }}.stepType" :label="__('Type')">
            <flux:select.option value="sequential">{{ __('Approval · sequential') }}</flux:select.option>
            <flux:select.option value="parallel">{{ __('Approval · parallel') }}</flux:select.option>
            <flux:select.option value="quorum">{{ __('Approval · quorum') }}</flux:select.option>
            <flux:select.option value="conditional">{{ __('Condition') }}</flux:select.option>
            <flux:select.option value="auto">{{ __('Automatic action') }}</flux:select.option>
            <flux:select.option value="notification">{{ __('Notification') }}</flux:select.option>
        </flux:select>
        @if (! in_array($selected['stepType'], ['conditional', 'auto', 'notification'], true))
            <flux:select wire:model="steps.{{ $index }}.completionRule" :label="__('Group completion')">
                <flux:select.option value="first_response">{{ __('First response') }}</flux:select.option>
                <flux:select.option value="all">{{ __('All assignees') }}</flux:select.option>
                <flux:select.option value="quorum">{{ __('Quorum N-of-M') }}</flux:select.option>
            </flux:select>
            @if (($selected['completionRule'] ?? '') === 'quorum' || $selected['stepType'] === 'quorum')
                <flux:input wire:model="steps.{{ $index }}.quorumCount" type="number" min="1" :label="__('Approvals required')" />
            @endif
            <flux:select wire:model.live="steps.{{ $index }}.assigneeType" :label="__('Assign by')">
                @foreach (\App\Enums\AssigneeType::cases() as $type)
                    <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @if (in_array($selected['assigneeType'], ['user', 'role', 'department'], true))
                <x-ui.assignee-picker
                    :type="$selected['assigneeType']"
                    query="assigneeQuery"
                    :selected="$selectedAssignees"
                    :results="$assigneeResults"
                    add="addAssignee"
                    remove="removeAssignee"
                />
            @elseif ($selected['assigneeType'] === 'form_field')
                <flux:input wire:model="steps.{{ $index }}.assigneeField" :label="__('User field key')" :description="__('The form field that picks the approver.')" />
            @elseif ($selected['assigneeType'] === 'external')
                <flux:input wire:model="steps.{{ $index }}.assigneeResolver" :label="__('External resolver key')" />
            @else
                <p class="text-xs text-zinc-500">{{ $selected['assigneeType'] === 'requester_manager' ? __("Resolved from the requester's line manager at submit time.") : __('Resolved from the request or requester department manager at submit time.') }}</p>
            @endif
            <flux:select wire:model="steps.{{ $index }}.timeoutCalendar" :label="__('Deadline calendar')" :description="__('Business hours use the organization working days, office hours, and holidays. Elapsed hours count every hour on the clock.')">
                <flux:select.option value="elapsed">{{ __('Elapsed hours') }}</flux:select.option>
                <flux:select.option value="business">{{ __('Business hours') }}</flux:select.option>
            </flux:select>
        @endif
        @if ($selected['stepType'] === 'conditional')
            @php
                $branchLabel = function (mixed $key) use ($steps): string {
                    $position = \App\Livewire\Forms\StepConditionForm::position($steps, $key);
                    if ($position !== null) {
                        return $position.'. '.$steps[$position - 1]['name'];
                    }

                    return blank($key) ? __('complete the workflow') : __('a removed step — choose again');
                };
            @endphp
            <flux:input wire:model="steps.{{ $index }}.conditionField" :label="__('Field')" placeholder="priority or fields.amount" :description="__('A request field, or a form field as fields.key.')" />
            <flux:error name="steps.{{ $index }}.conditionField" />
            <flux:select wire:model.live="steps.{{ $index }}.conditionOperator" :label="__('Operator')" data-test="condition-operator">
                @foreach (\App\Livewire\Forms\StepConditionForm::OPERATORS as $value => $label)
                    <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="steps.{{ $index }}.conditionValue" :label="__('Value')" :disabled="($selected['conditionOperator'] ?? '') === 'present'" />
            @if (($selected['conditionExtraRules'] ?? []) !== [])
                <p class="text-xs text-zinc-500">{{ trans_choice('{1} :count more rule from an earlier version is kept unchanged.|[2,*] :count more rules from an earlier version are kept unchanged.', count($selected['conditionExtraRules']), ['count' => count($selected['conditionExtraRules'])]) }}</p>
            @endif
            <flux:button size="sm" wire:click="beginConnect('true')" data-test="connect-true">{{ $connectMode === 'true' ? __('Click a later node') : __('Connect true branch') }}</flux:button>
            <flux:button size="sm" wire:click="beginConnect('false')">{{ $connectMode === 'false' ? __('Click a later node') : __('Connect false branch') }}</flux:button>
            <p class="text-xs text-zinc-500" data-test="branch-summary">{{ __('True → :true · False → :false', ['true' => $branchLabel($selected['trueStep'] ?? null), 'false' => $branchLabel($selected['falseStep'] ?? null)]) }}</p>
            <flux:error name="steps.{{ $index }}.trueStep" />
            <flux:error name="steps.{{ $index }}.falseStep" />
        @endif
        <div class="flex flex-wrap gap-2">
            <flux:button size="sm" wire:click="duplicateSelected">{{ __('Duplicate') }}</flux:button>
            <flux:button size="sm" variant="danger" wire:click="deleteSelected">{{ __('Delete') }}</flux:button>
        </div>
    </div>
</flux:card>
