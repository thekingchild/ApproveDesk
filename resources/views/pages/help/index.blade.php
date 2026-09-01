<x-layouts::app :title="__('Help')">
    <div class="space-y-8">
        <x-ui.page-header
            :title="__('Help centre')"
            :description="__('Guides for the screens you use. Installation and server setup are not covered here.')"
        />

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <flux:card>
                <div class="flex items-start gap-3">
                    <div class="ad-empty-state__icon shrink-0"><flux:icon icon="document-plus" class="size-5" /></div>
                    <div>
                        <flux:heading size="sm">{{ __('Create a request') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Start a submission, attach supporting files, save it as a draft, and submit when it is ready.') }}</flux:text>
                        <div class="mt-3 flex flex-wrap gap-3">
                            <flux:link :href="route('submissions.choose-type')" class="inline-flex" wire:navigate>{{ __('Start a submission') }}</flux:link>
                            <flux:link :href="route('help.show', 'requests')" class="inline-flex" wire:navigate>{{ __('Read the guide') }}</flux:link>
                        </div>
                    </div>
                </div>
            </flux:card>

            <flux:card>
                <div class="flex items-start gap-3">
                    <div class="ad-empty-state__icon shrink-0"><flux:icon icon="check-circle" class="size-5" /></div>
                    <div>
                        <flux:heading size="sm">{{ __('Review approvals') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Open an assigned request, review its evidence and history, then record the appropriate decision.') }}</flux:text>
                        <div class="mt-3 flex flex-wrap gap-3">
                            <flux:link :href="route('approvals.inbox')" class="inline-flex" wire:navigate>{{ __('Open approvals') }}</flux:link>
                            <flux:link :href="route('help.show', 'approvals')" class="inline-flex" wire:navigate>{{ __('Read the guide') }}</flux:link>
                        </div>
                    </div>
                </div>
            </flux:card>

            <flux:card>
                <div class="flex items-start gap-3">
                    <div class="ad-empty-state__icon shrink-0"><flux:icon icon="bell" class="size-5" /></div>
                    <div>
                        <flux:heading size="sm">{{ __('Control notifications') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Choose which approval events reach you immediately and which are included in scheduled digests.') }}</flux:text>
                        <div class="mt-3 flex flex-wrap gap-3">
                            <flux:link :href="route('notifications.edit')" class="inline-flex" wire:navigate>{{ __('Notification settings') }}</flux:link>
                            <flux:link :href="route('help.show', 'account-settings')" class="inline-flex" wire:navigate>{{ __('Read the guide') }}</flux:link>
                        </div>
                    </div>
                </div>
            </flux:card>
        </div>

        @foreach ($groups as $group => $groupArticles)
            <section aria-labelledby="help-group-{{ \Illuminate\Support\Str::slug($group) }}" class="space-y-3">
                <flux:heading size="sm" :id="'help-group-'.\Illuminate\Support\Str::slug($group)">{{ $group }}</flux:heading>

                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($groupArticles as $article)
                        <a
                            href="{{ route('help.show', $article->slug) }}"
                            class="rounded-xl border border-zinc-200 bg-white p-4 hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-blue-600"
                            wire:navigate
                            data-test="help-article-{{ $article->slug }}"
                        >
                            <div class="flex items-start gap-3">
                                <div class="ad-empty-state__icon shrink-0">
                                    <flux:icon :icon="$article->icon" class="size-5" />
                                </div>
                                <div>
                                    <p class="font-semibold text-zinc-950 dark:text-white">{{ $article->title }}</p>
                                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $article->summary }}</p>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        @if ($hasAdministrationHelp)
            <x-ui.alert variant="info" :title="__('Looking for a screen?')">
                {{ __('Start with Feature definitions for the words used in ApproveDesk, then open the guide that matches the screen you are on.') }}
            </x-ui.alert>
        @else
            <x-ui.alert variant="info" :title="__('Need administrator assistance?')">
                {{ __('Contact your organization administrator and include the submission reference number shown on the request.') }}
            </x-ui.alert>
        @endif
    </div>
</x-layouts::app>
