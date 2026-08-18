<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main id="main-content" tabindex="-1" class="ad-main">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
