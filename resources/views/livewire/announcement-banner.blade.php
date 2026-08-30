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
                        <div class="prose prose-sm mt-2 max-w-none dark:prose-invert">{!! $announcement->message !!}</div>
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
