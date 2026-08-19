@props([
    'article',
])

@php
    $item = app(\App\Support\HelpCatalogue::class)->find($article);
    $user = auth()->user();
@endphp

@if ($item && $user && $item->visibleTo($user))
    <flux:tooltip :content="$item->title" position="bottom">
        <flux:button
            :href="route('help.show', $item->slug)"
            variant="ghost"
            icon="lifebuoy"
            square
            :aria-label="__('Open help: :title', ['title' => $item->title])"
            wire:navigate
            data-test="contextual-help"
            {{ $attributes->class('shrink-0') }}
        />
    </flux:tooltip>
@endif
