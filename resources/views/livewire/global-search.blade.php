<div
    x-on:keydown.window.prevent.cmd.k="$flux.modal('global-search').show()"
    x-on:keydown.window.prevent.ctrl.k="$flux.modal('global-search').show()"
>
    <flux:modal name="global-search" class="w-full max-w-2xl" x-on:close="$wire.clear()">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Search ApproveDesk') }}</flux:heading>
                <flux:subheading>{{ __('Find requests you are authorized to view.') }}</flux:subheading>
            </div>

            <flux:input
                wire:model.live.debounce.250ms="query"
                icon="magnifying-glass"
                :placeholder="__('Reference, title, requester, status, or metadata')"
                :aria-label="__('Search submissions')"
                autocomplete="off"
                autofocus
                data-test="global-search-input"
            />

            <div class="max-h-[min(60vh,32rem)] overflow-y-auto" aria-live="polite">
                @if (mb_strlen(trim($query)) < 2)
                    <x-ui.empty-state
                        :title="__('Search all requests')"
                        :description="__('Enter at least two characters. Results always follow your submission access policy.')"
                        icon="magnifying-glass"
                        class="py-8"
                    />
                @else
                    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700" data-test="global-search-results">
                        @forelse ($this->results as $submission)
                            <li>
                                <a
                                    href="{{ route('submissions.show', $submission) }}"
                                    class="group grid gap-1 rounded-lg px-3 py-3 hover:bg-zinc-100 focus-visible:outline-offset-[-2px] dark:hover:bg-zinc-800"
                                    wire:navigate
                                >
                                    <span class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="font-semibold text-zinc-900 group-hover:text-blue-700 dark:text-white dark:group-hover:text-blue-300">{{ $submission->title }}</span>
                                        <flux:badge :color="['draft' => 'zinc', 'pending' => 'blue', 'in_review' => 'blue', 'approved' => 'lime', 'rejected' => 'red', 'returned' => 'amber', 'awaiting_information' => 'amber', 'withdrawn' => 'zinc'][$submission->status->value] ?? 'zinc'">
                                            {{ str($submission->status->value)->replace('_', ' ') }}
                                        </flux:badge>
                                    </span>
                                    <span class="flex flex-wrap gap-x-3 gap-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                                        <span class="font-mono text-xs">{{ $submission->reference_number }}</span>
                                        <span>{{ $submission->submitter->name }}</span>
                                        @if ($submission->department)<span>{{ $submission->department->name }}</span>@endif
                                        <span>{{ $submission->updated_at?->diffForHumans() }}</span>
                                    </span>
                                </a>
                            </li>
                        @empty
                            <li>
                                <x-ui.empty-state
                                    :title="__('No authorized results')"
                                    :description="__('Check the spelling or try a reference, requester, or status.')"
                                    icon="document-magnifying-glass"
                                    class="py-8"
                                />
                            </li>
                        @endforelse
                    </ul>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
