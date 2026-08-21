@props([
    'sidebar' => false,
])

@php($logoUrl = \App\Support\Branding::logoUrl())
@php($appName = \App\Support\Branding::name())

@if($sidebar)
    <flux:sidebar.brand :name="$appName" {{ $attributes }}>
        <x-slot name="logo" @class([
            'flex aspect-square size-8 items-center justify-center rounded-md overflow-hidden',
            'bg-accent-content text-accent-foreground' => $logoUrl === null,
        ])>
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="" class="size-8 object-contain" />
            @else
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            @endif
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$appName" {{ $attributes }}>
        <x-slot name="logo" @class([
            'flex aspect-square size-8 items-center justify-center rounded-md overflow-hidden',
            'bg-accent-content text-accent-foreground' => $logoUrl === null,
        ])>
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="" class="size-8 object-contain" />
            @else
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            @endif
        </x-slot>
    </flux:brand>
@endif
