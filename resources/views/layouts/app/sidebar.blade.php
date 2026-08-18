<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <a class="ad-skip-link" href="#main-content">{{ __('Skip to main content') }}</a>

        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:modal.trigger name="global-search">
                <flux:sidebar.search :placeholder="__('Search')" kbd="⌘K" data-test="global-search-trigger" />
            </flux:modal.trigger>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Approvals')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="inbox" :href="route('approvals.inbox')" :current="request()->routeIs('approvals.*')" wire:navigate>
                        {{ __('Approvals') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Submissions')" class="grid">
                    <flux:sidebar.item icon="folder" :href="route('submissions.index')" :current="request()->routeIs('submissions.index')" wire:navigate>
                        {{ auth()->user()->can('submissions.view.all') ? __('All submissions') : __('My submissions') }}
                    </flux:sidebar.item>
                    @can('create', \App\Models\Submission::class)
                        <flux:sidebar.item icon="plus-circle" :href="route('submissions.create')" :current="request()->routeIs('submissions.create')" wire:navigate>
                            {{ __('New submission') }}
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                @if (auth()->user()->can('viewAny', \App\Models\User::class) || auth()->user()->can('viewAny', \App\Models\Department::class) || auth()->user()->can('viewAny', \App\Models\WorkflowTemplate::class) || auth()->user()->can('reports.view') || auth()->user()->can('viewAny', \App\Models\WebhookEndpoint::class))
                    <flux:sidebar.group :heading="__('Administration')" class="grid">
                        @can('viewAny', \App\Models\User::class)
                            <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                                {{ __('Users') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('viewAny', \App\Models\Department::class)
                            <flux:sidebar.item icon="building-office" :href="route('admin.departments.index')" :current="request()->routeIs('admin.departments.*')" wire:navigate>
                                {{ __('Departments') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('viewAny', \App\Models\WorkflowTemplate::class)
                            <flux:sidebar.item icon="arrows-right-left" :href="route('admin.workflow-templates.index')" :current="request()->routeIs('admin.workflow-templates.*')" wire:navigate>
                                {{ __('Workflow templates') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('reports.view')
                            <flux:sidebar.item icon="chart-bar" :href="route('admin.analytics.index')" :current="request()->routeIs('admin.analytics.*')" wire:navigate>
                                {{ __('Analytics') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('viewAny', \App\Models\WebhookEndpoint::class)
                            <flux:sidebar.item icon="rss" :href="route('admin.webhooks.index')" :current="request()->routeIs('admin.webhooks.*')" wire:navigate>
                                {{ __('Webhooks') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <livewire:notification-indicator />
                <flux:sidebar.item icon="lifebuoy" :href="route('help')" :current="request()->routeIs('help')" wire:navigate>
                    {{ __('Help') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" :label="__('Open navigation')" dusk="mobile-navigation" />

            <flux:spacer />

            <flux:modal.trigger name="global-search">
                <flux:button variant="ghost" icon="magnifying-glass" square :aria-label="__('Search')" />
            </flux:modal.trigger>

            <livewire:notification-indicator mobile />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <livewire:global-search />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
