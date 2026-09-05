@php
    $name = $name ?? '';
    $label = $label ?? $name;
    $stored = \App\Support\SystemSettings::hasSecret($name);
@endphp

<div class="grid gap-3 sm:grid-cols-[1fr_14rem]">
    <flux:input
        name="{{ $name }}"
        type="password"
        :label="$label"
        autocomplete="new-password"
        :description="$stored
            ? __('A value is stored. Leave this blank and keep the stored value unless you need to change it.')
            : __('No value is stored yet. The server default is used until you save one.')"
    />
    <flux:select name="{{ $name }}_action" :label="__('When you save')">
        <option value="keep">{{ __('Keep stored value') }}</option>
        <option value="replace">{{ __('Save the value I typed') }}</option>
        <option value="clear">{{ __('Remove stored value') }}</option>
    </flux:select>
</div>
