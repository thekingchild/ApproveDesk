<div>
    @if ($mobile)
        <div class="relative">
            <flux:button
                :href="route('notifications.index')"
                variant="ghost"
                icon="bell"
                square
                :aria-label="$unreadCount ? __('Notifications, :count unread', ['count' => $unreadCount]) : __('Notifications')"
                wire:navigate
            />
            @if ($unreadCount)
                <span class="pointer-events-none absolute -end-1 -top-1 grid min-h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[0.6875rem] font-semibold text-white" aria-hidden="true">
                    {{ $unreadCount > 99 ? '99+' : $unreadCount }}
                </span>
            @endif
        </div>
    @else
        <flux:sidebar.item
            icon="bell"
            :href="route('notifications.index')"
            :current="request()->routeIs('notifications.index')"
            :badge="$unreadCount ?: null"
            wire:navigate
        >
            {{ __('Notifications') }}
        </flux:sidebar.item>
    @endif
</div>
