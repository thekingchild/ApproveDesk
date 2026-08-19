@props([
    'variant' => 'sidebar',
])

@php
    $user = auth()->user();
    $catalogue = app(\App\Support\HelpCatalogue::class);
    $menuArticles = $user ? $catalogue->menuArticles($user) : collect();
    $isHelp = request()->routeIs('help', 'help.show');
@endphp

@if ($variant === 'sidebar')
    <flux:dropdown position="top" align="start" data-test="help-menu">
        <flux:sidebar.item
            icon="lifebuoy"
            as="button"
            :current="$isHelp"
            data-test="help-menu-trigger"
        >
            {{ __('Help') }}
        </flux:sidebar.item>

        <x-help-menu.items :articles="$menuArticles" />
    </flux:dropdown>
@elseif ($variant === 'mobile')
    <flux:dropdown position="bottom" align="end" data-test="help-menu">
        <flux:button
            variant="ghost"
            icon="lifebuoy"
            square
            :aria-label="__('Help')"
            data-test="help-menu-trigger"
        />

        <x-help-menu.items :articles="$menuArticles" />
    </flux:dropdown>
@else
    <flux:dropdown position="bottom" align="end" data-test="help-menu">
        <flux:tooltip :content="__('Help')" position="bottom">
            <flux:navbar.item
                class="h-10 max-lg:hidden [&>div>svg]:size-5"
                icon="lifebuoy"
                as="button"
                :label="__('Help')"
                data-test="help-menu-trigger"
            />
        </flux:tooltip>

        <x-help-menu.items :articles="$menuArticles" />
    </flux:dropdown>
@endif
