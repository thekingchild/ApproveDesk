@props([
    'variant' => 'info',
    'title' => null,
])

@php
    $icon = match ($variant) {
        'success' => 'check-circle',
        'warning' => 'exclamation-triangle',
        'danger' => 'x-circle',
        default => 'information-circle',
    };
@endphp

<div
    {{ $attributes->class('ad-alert') }}
    data-variant="{{ $variant }}"
    @if ($variant === 'danger') role="alert" @else role="status" @endif
>
    <flux:icon :icon="$icon" class="mt-0.5 size-5" aria-hidden="true" />
    <div>
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-1' => $title])>{{ $slot }}</div>
    </div>
</div>
