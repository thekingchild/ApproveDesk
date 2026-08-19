@props([
    'articles',
])

<flux:menu class="min-w-56">
    <flux:menu.item :href="route('help')" icon="lifebuoy" wire:navigate data-test="help-centre-link">
        {{ __('Help centre') }}
    </flux:menu.item>

    @if ($articles->isNotEmpty())
        <flux:menu.separator />

        @foreach ($articles as $article)
            <flux:menu.item
                :href="route('help.show', $article->slug)"
                :icon="$article->icon"
                wire:navigate
                data-test="help-menu-{{ $article->slug }}"
            >
                {{ $article->title }}
            </flux:menu.item>
        @endforeach
    @endif
</flux:menu>
