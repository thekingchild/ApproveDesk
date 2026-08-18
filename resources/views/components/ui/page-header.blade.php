@props([
    'title',
    'description' => null,
    'eyebrow' => null,
])

<header {{ $attributes->class('ad-page-header') }}>
    <div>
        @if ($eyebrow)
            <p class="ad-eyebrow">{{ $eyebrow }}</p>
        @endif

        <h1 class="ad-page-title">{{ $title }}</h1>

        @if ($description)
            <p class="ad-page-description">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div data-actions class="flex items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</header>
