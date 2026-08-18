@props([
    'items' => [],
    'back' => null,
    'backLabel' => 'Back',
])

<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-3') }}>
    <nav aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-300">
            @foreach ($items as $item)
                <li class="flex items-center gap-1.5">
                    @if (! $loop->first)
                        <flux:icon icon="chevron-right" class="size-3.5 text-zinc-400 rtl:rotate-180" aria-hidden="true" />
                    @endif

                    @if (! empty($item['url']) && ! $loop->last)
                        <a href="{{ $item['url'] }}" class="font-medium hover:text-zinc-950 dark:hover:text-white" wire:navigate>{{ $item['label'] }}</a>
                    @else
                        <span @if ($loop->last) aria-current="page" class="text-zinc-900 dark:text-white" @endif>{{ $item['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    @if ($back)
        <flux:button :href="$back" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
            {{ __($backLabel) }}
        </flux:button>
    @endif
</div>
