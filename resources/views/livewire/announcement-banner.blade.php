<div>
    @if ($announcements->isNotEmpty())
        <section class="mb-6 space-y-3" aria-label="{{ __('Announcements') }}" data-test="announcement-banners">
        @foreach ($announcements as $announcement)
            <div class="rounded-xl border border-[var(--ad-primary)] bg-[color-mix(in_srgb,var(--ad-primary)_8%,var(--ad-surface-panel))] p-4 shadow-sm" role="status" wire:key="global-announcement-{{ $announcement->id }}">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold">{{ $announcement->title }}</h2>
                            <span class="rounded-full border px-2 py-0.5 text-xs font-medium">
                                {{ match ($announcement->display_mode) {
                                    \App\Enums\AnnouncementDisplayMode::Sticky => __('Sticky announcement'),
                                    \App\Enums\AnnouncementDisplayMode::Acknowledge => __('Please acknowledge'),
                                    \App\Enums\AnnouncementDisplayMode::ShowOnce => __('Announcement'),
                                } }}
                            </span>
                        </div>
                        @php
                            $excerptSource = preg_replace('/<\/?(?:p|li|h[1-4]|blockquote|pre|ul|ol)[^>]*>|<br\s*\/?>/i', ' ', $announcement->message) ?? $announcement->message;
                            $plainMessage = \Illuminate\Support\Str::squish(html_entity_decode(strip_tags($excerptSource)));
                            $blockCount = preg_match_all('/<(?:p|br|li|h[1-4]|blockquote|pre)\b/i', $announcement->message);
                            $isLong = \Illuminate\Support\Str::length($plainMessage) > 280 || $blockCount >= 4;
                        @endphp
                        @if ($isLong)
                            <div x-data="{ expanded: false }" class="mt-2">
                                <p
                                    id="announcement-excerpt-{{ $announcement->id }}"
                                    class="text-sm leading-6 text-zinc-700 dark:text-zinc-200"
                                    x-show="! expanded"
                                    data-test="announcement-excerpt"
                                >
                                    {{ \Illuminate\Support\Str::limit($plainMessage, 240) }}
                                </p>
                                <div
                                    id="announcement-message-{{ $announcement->id }}"
                                    class="prose prose-sm max-w-none dark:prose-invert"
                                    x-show="expanded"
                                    x-cloak
                                >
                                    {!! $announcement->safeMessage() !!}
                                </div>
                                <button
                                    type="button"
                                    class="mt-2 inline-flex min-h-8 items-center rounded-md text-sm font-semibold text-[var(--ad-primary)] underline decoration-dotted underline-offset-4 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ad-focus)]"
                                    x-on:click="expanded = ! expanded"
                                    x-bind:aria-expanded="expanded.toString()"
                                    aria-controls="announcement-message-{{ $announcement->id }}"
                                    data-test="announcement-disclosure"
                                >
                                    <span x-show="! expanded">{{ __('Show more') }}</span>
                                    <span x-show="expanded" x-cloak>{{ __('Show less') }}</span>
                                </button>
                            </div>
                        @else
                            <div id="announcement-message-{{ $announcement->id }}" class="prose prose-sm mt-2 max-w-none dark:prose-invert">
                                {!! $announcement->safeMessage() !!}
                            </div>
                        @endif
                        <p class="mt-2 text-xs text-zinc-600 dark:text-zinc-300">
                            {{ __('Published :time', ['time' => $announcement->published_at?->diffForHumans()]) }}
                        </p>
                    </div>
                    @if ($announcement->display_mode === \App\Enums\AnnouncementDisplayMode::Acknowledge)
                        <flux:button size="sm" variant="primary" wire:click="acknowledge({{ $announcement->id }})">
                            {{ __('Mark as read') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        @endforeach
        </section>
    @endif
</div>
