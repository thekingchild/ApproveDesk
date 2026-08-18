<x-layouts::app :title="__('Help')">
    <div class="space-y-8">
        <x-ui.page-header
            :title="__('Help centre')"
            :description="__('Practical guidance for submitting, reviewing, and administering approval requests.')"
        />

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <flux:card>
                <div class="flex items-start gap-3">
                    <div class="ad-empty-state__icon shrink-0"><flux:icon icon="document-plus" class="size-5" /></div>
                    <div>
                        <flux:heading size="sm">{{ __('Create a request') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Start a submission, attach supporting files, save it as a draft, and submit when it is ready.') }}</flux:text>
                        <flux:link :href="route('submissions.create')" class="mt-3 inline-flex" wire:navigate>{{ __('Start a submission') }}</flux:link>
                    </div>
                </div>
            </flux:card>

            <flux:card>
                <div class="flex items-start gap-3">
                    <div class="ad-empty-state__icon shrink-0"><flux:icon icon="check-circle" class="size-5" /></div>
                    <div>
                        <flux:heading size="sm">{{ __('Review approvals') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Open an assigned request, review its evidence and history, then record the appropriate decision.') }}</flux:text>
                        <flux:link :href="route('approvals.inbox')" class="mt-3 inline-flex" wire:navigate>{{ __('Open approvals') }}</flux:link>
                    </div>
                </div>
            </flux:card>

            <flux:card>
                <div class="flex items-start gap-3">
                    <div class="ad-empty-state__icon shrink-0"><flux:icon icon="bell" class="size-5" /></div>
                    <div>
                        <flux:heading size="sm">{{ __('Control notifications') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Choose which approval events reach you immediately and which are included in scheduled digests.') }}</flux:text>
                        <flux:link :href="route('notifications.edit')" class="mt-3 inline-flex" wire:navigate>{{ __('Notification settings') }}</flux:link>
                    </div>
                </div>
            </flux:card>
        </div>

        <flux:card>
            <flux:heading size="sm">{{ __('Common workflow states') }}</flux:heading>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><dt class="font-semibold text-zinc-900 dark:text-white">{{ __('Draft') }}</dt><dd class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('Only the owner can edit it. Submit it when all information is complete.') }}</dd></div>
                <div><dt class="font-semibold text-zinc-900 dark:text-white">{{ __('In review') }}</dt><dd class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('The workflow is active and waiting for one or more approvers.') }}</dd></div>
                <div><dt class="font-semibold text-zinc-900 dark:text-white">{{ __('Returned') }}</dt><dd class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('The requester must revise the submission before approval can continue.') }}</dd></div>
                <div><dt class="font-semibold text-zinc-900 dark:text-white">{{ __('Completed') }}</dt><dd class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('The final workflow outcome and its full action history are available on the request.') }}</dd></div>
            </dl>
        </flux:card>

        <x-ui.alert variant="info" :title="__('Need administrator assistance?')">
            {{ __('Contact your organization administrator and include the submission reference number shown on the request.') }}
        </x-ui.alert>
    </div>
</x-layouts::app>
