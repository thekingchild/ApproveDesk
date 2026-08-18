@props([
    'title',
    'description' => null,
    'icon' => 'inbox',
])

<div {{ $attributes->class('ad-empty-state') }}>
    <div class="ad-empty-state__icon" aria-hidden="true">
        <flux:icon :icon="$icon" class="size-5" />
    </div>
    <p class="font-semibold text-zinc-900 dark:text-zinc-50">{{ $title }}</p>
    @if ($description)
        <p class="max-w-md text-sm text-zinc-600 dark:text-zinc-300">{{ $description }}</p>
    @endif
    @isset($actions)
        <div class="mt-2 flex flex-wrap justify-center gap-2">{{ $actions }}</div>
    @endisset
</div>
