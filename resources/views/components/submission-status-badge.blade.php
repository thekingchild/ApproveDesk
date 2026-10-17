@props(['status'])
{{-- One status badge for every list and page (RV-ARC-15). --}}
<flux:badge :color="$status->color()" {{ $attributes }}>{{ $status->label() }}</flux:badge>
