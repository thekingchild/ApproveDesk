@props([
    'type' => 'user',
    'query' => '',
    'selected' => collect(),
    'results' => collect(),
    'add' => '',
    'remove' => '',
    'stepIndex' => null,
    'placeholder' => 'Type at least two characters to search…',
])

<div class="space-y-2" data-test="assignee-picker">
    <div class="flex flex-wrap gap-1" data-test="assignee-chips">
        @forelse ($selected as $item)
            <span class="inline-flex items-center gap-1 rounded-full border border-zinc-300 bg-zinc-50 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-800">
                {{ $item->name }}
                <button type="button" class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white" wire:click="{{ $remove }}({{ $stepIndex !== null ? $stepIndex.', ' : '' }}{{ $item->id }})" aria-label="{{ __('Remove :name', ['name' => $item->name]) }}" data-test="remove-assignee-{{ $item->id }}">×</button>
            </span>
        @empty
            <span class="text-xs text-zinc-500">{{ __('No assignees selected.') }}</span>
        @endforelse
    </div>

    <flux:input
        wire:model.live.debounce.300ms="{{ $query }}"
        icon="magnifying-glass"
        :placeholder="__($placeholder)"
        data-test="assignee-search"
    />

    @if ($results->isNotEmpty())
        <ul class="max-h-40 overflow-auto rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="assignee-results">
            @foreach ($results as $item)
                <li>
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        wire:click="{{ $add }}({{ $stepIndex !== null ? $stepIndex.', ' : '' }}{{ $item->id }})"
                        data-test="add-assignee-{{ $item->id }}"
                    >
                        <span>{{ $item->name }}</span>
                        @if (isset($item->email))
                            <span class="text-xs text-zinc-500">{{ $item->email }}</span>
                        @endif
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
