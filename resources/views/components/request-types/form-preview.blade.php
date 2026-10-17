{{-- Live preview of the form being built (ADR 0006 split). Visibility and required state come from the schema compiler, as on the real form (RV-ARC-05). --}}
@props(['fields', 'previewState', 'previewValues'])

<details class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" dusk="builder-preview">
    <summary class="cursor-pointer font-semibold">{{ __('Preview form') }}</summary>
    <p class="mt-2 text-sm text-zinc-500">{{ __('Enter sample values to see visibility and required rules behave exactly as requesters will experience them. This preview does not save anything.') }}</p>
    <div class="mt-4 space-y-4">
        @foreach ($fields as $index => $field)
            @if ($previewState[$index]['visible'] ?? true)
                <div wire:key="preview-field-{{ $index }}" data-test="preview-field-{{ $index }}">
                    @php($required = $previewState[$index]['required'] ?? false)
                    @if (in_array($field['type'], ['select', 'radio'], true))
                        <flux:select
                            wire:model.live="previewValues.{{ $field['key'] }}"
                            :label="$field['label'] ?: __('Untitled field')"
                            :required="$required"
                            :description="$field['help']"
                        >
                            <flux:select.option value="">—</flux:select.option>
                            @foreach (\App\Livewire\Forms\RequestFieldsForm::options((string) ($field['options'] ?? '')) as $choice)
                                <flux:select.option :value="$choice">{{ $choice }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @elseif ($field['type'] === 'multi_select')
                        <flux:checkbox.group wire:model.live="previewValues.{{ $field['key'] }}" :label="$field['label'] ?: __('Untitled field')" :description="$field['help']">
                            @foreach (\App\Livewire\Forms\RequestFieldsForm::options((string) ($field['options'] ?? '')) as $choice)
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
                    @if ($required && blank($previewValues[$field['key']] ?? null))
                        <p class="mt-1 text-sm text-amber-600 dark:text-amber-400">{{ __('Required by the current conditional rule.') }}</p>
                    @endif
                </div>
            @endif
        @endforeach
    </div>
</details>
