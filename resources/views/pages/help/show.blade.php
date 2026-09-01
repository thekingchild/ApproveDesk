<x-layouts::app :title="$article->title">
    <div class="space-y-6">
        <x-ui.breadcrumbs
            :items="[
                ['label' => __('Dashboard'), 'url' => route('dashboard')],
                ['label' => __('Help centre'), 'url' => route('help')],
                ['label' => $article->title],
            ]"
            :back="route('help')"
            back-label="Back to help centre"
        />

        <x-ui.page-header :title="$article->title" :description="$article->summary">
            @if ($article->canOpenScreen(auth()->user()) && \Illuminate\Support\Facades\Route::has($article->screenRoute))
                <x-slot:actions>
                    <flux:button :href="route($article->screenRoute)" variant="primary" wire:navigate>
                        {{ __('Open this screen') }}
                    </flux:button>
                </x-slot:actions>
            @endif
        </x-ui.page-header>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_16rem]">
            <flux:card class="ad-help-prose" data-test="help-article-body">
                {!! $html !!}
            </flux:card>

            <aside class="space-y-3">
                <flux:heading size="sm">{{ $article->group }}</flux:heading>
                <nav aria-label="{{ __('Related guides') }}" class="grid gap-1">
                    @foreach ($siblings as $sibling)
                        <a
                            href="{{ route('help.show', $sibling->slug) }}"
                            @class([
                                'rounded-lg px-3 py-2 text-sm',
                                'bg-zinc-100 font-semibold text-zinc-950 dark:bg-zinc-800 dark:text-white' => $sibling->slug === $article->slug,
                                'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800' => $sibling->slug !== $article->slug,
                            ])
                            wire:navigate
                        >
                            {{ $sibling->title }}
                        </a>
                    @endforeach
                </nav>
            </aside>
        </div>
    </div>
</x-layouts::app>
