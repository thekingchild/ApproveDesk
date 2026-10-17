{{-- One field card in the request-type builder (ADR 0006 split). Rendered inside the builder, so wire: bindings reach it. --}}
@props(['field', 'index', 'fields'])

<section wire:key="field-{{ $index }}" wire:sort:item="{{ $index }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" dusk="builder-field-{{ $index }}">
    <div class="mb-3 flex items-center justify-between"><div class="font-semibold" wire:sort:handle>⋮⋮ {{ __('Field') }} {{ $index + 1 }}</div><div class="flex gap-1"><flux:button size="sm" wire:click="moveField({{ $index }}, -1)" aria-label="Move field up">↑</flux:button><flux:button size="sm" wire:click="moveField({{ $index }}, 1)" aria-label="Move field down">↓</flux:button><flux:button size="sm" wire:click="duplicateField({{ $index }})">{{ __('Duplicate') }}</flux:button><flux:button size="sm" variant="danger" wire:click="removeField({{ $index }})">{{ __('Delete') }}</flux:button></div></div>
    <div class="grid gap-3 md:grid-cols-3">
        <flux:input wire:model.live.debounce.400ms="fields.{{ $index }}.label" label="Label" required dusk="field-label-{{ $index }}" />
        <flux:select wire:model.live="fields.{{ $index }}.type" label="Type" dusk="field-type-{{ $index }}">@foreach (\App\Enums\RequestFieldType::cases() as $case)<flux:select.option :value="$case->value">{{ \Illuminate\Support\Str::headline($case->value) }}</flux:select.option>@endforeach</flux:select>
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
                        :checked="in_array($source['key'], \App\Livewire\Forms\RequestFieldsForm::options($field['calculationFields'] ?? ''), true)"
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
            <flux:select wire:model="fields.{{ $index }}.visibilityOperator" label="Operator">@foreach (\App\Services\RequestTypes\RequestSchemaCompiler::CONDITION_OPERATORS as $operator)<flux:select.option :value="$operator">{{ \Illuminate\Support\Str::headline($operator) }}</flux:select.option>@endforeach</flux:select>
            <div>
                @php($conditionSource = collect($fields)->take($index)->firstWhere('key', $field['visibilityField'] ?? ''))
                @if (in_array($conditionSource['type'] ?? '', ['select', 'multi_select', 'radio'], true))
                    <flux:select wire:model="fields.{{ $index }}.visibilityValue" label="Value">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach (\App\Livewire\Forms\RequestFieldsForm::options((string) ($conditionSource['options'] ?? '')) as $choice)
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
            <flux:select wire:model="fields.{{ $index }}.requiredOperator" label="Operator">@foreach (\App\Services\RequestTypes\RequestSchemaCompiler::CONDITION_OPERATORS as $operator)<flux:select.option :value="$operator">{{ \Illuminate\Support\Str::headline($operator) }}</flux:select.option>@endforeach</flux:select>
            <div>
                @php($requiredSource = collect($fields)->take($index)->firstWhere('key', $field['requiredField'] ?? ''))
                @if (in_array($requiredSource['type'] ?? '', ['select', 'multi_select', 'radio'], true))
                    <flux:select wire:model="fields.{{ $index }}.requiredValue" label="Value">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach (\App\Livewire\Forms\RequestFieldsForm::options((string) ($requiredSource['options'] ?? '')) as $choice)
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
