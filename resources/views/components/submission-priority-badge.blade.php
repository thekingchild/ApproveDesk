@props(['priority'])
<flux:badge :color="$priority->color()" {{ $attributes }}>{{ $priority->label() }}</flux:badge>
