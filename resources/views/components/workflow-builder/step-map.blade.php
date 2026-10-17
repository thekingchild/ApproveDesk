{{-- Canvas and list views of the builder's steps (ADR 0006 split). Rendered inside the builder, so wire: calls reach it. --}}
@props(['steps' => [], 'selectedIndex' => null, 'viewMode' => 'canvas', 'zoom' => 1])

@if ($viewMode === 'canvas')
    <div class="overflow-auto rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950" data-test="workflow-canvas">
        <div class="ad-workflow-canvas origin-top-left p-6" style="transform: scale({{ $zoom }}); width: 28rem; height: {{ 140 + count($steps) * 108 }}px;">
            <div class="ad-workflow-node ad-workflow-node--start">{{ __('Start') }}</div>
            @foreach ($steps as $index => $step)
                @php $kind = (\App\Enums\StepType::tryFrom((string) $step['stepType']) ?? \App\Enums\StepType::Sequential)->canvasKind(); @endphp
                <button
                    type="button"
                    wire:click="selectStep({{ $index }})"
                    @class(['ad-workflow-node', 'ad-workflow-node--'.$kind, 'ad-workflow-node--selected' => $selectedIndex === $index])
                    data-test="canvas-node-{{ $index }}"
                >
                    <span class="text-xs uppercase tracking-wide text-zinc-500">{{ $kind }}</span>
                    <span class="block font-semibold">{{ $step['name'] }}</span>
                </button>
            @endforeach
            <div class="ad-workflow-node ad-workflow-node--end">{{ __('End') }}</div>
        </div>
    </div>
@else
    <ol class="space-y-2" aria-label="{{ __('Workflow steps') }}" data-test="workflow-list">
        @foreach ($steps as $index => $step)
            <li>
                <div @class(['flex items-center gap-2 rounded-xl border p-3', 'border-blue-500' => $selectedIndex === $index, 'border-zinc-200 dark:border-zinc-700' => $selectedIndex !== $index])>
                    <button type="button" class="flex-1 text-left" wire:click="selectStep({{ $index }})" data-test="list-node-{{ $index }}">
                        <span class="font-semibold">{{ $index + 1 }}. {{ $step['name'] }}</span>
                        <span class="ml-2 text-sm text-zinc-500">{{ $step['stepType'] }}</span>
                    </button>
                    <flux:button size="xs" wire:click="moveStep({{ $index }}, -1)" :disabled="$index === 0" aria-label="Move up">↑</flux:button>
                    <flux:button size="xs" wire:click="moveStep({{ $index }}, 1)" :disabled="$index === count($steps) - 1" aria-label="Move down">↓</flux:button>
                </div>
            </li>
        @endforeach
    </ol>
@endif
