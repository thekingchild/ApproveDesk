<x-layouts::app :title="__('Design system')">
    <div class="space-y-10" data-test="design-system-reference">
        <x-ui.page-header
            eyebrow="Internal reference"
            :title="__('ApproveDesk design system')"
            :description="__('Canonical tokens and interface patterns for product work. This page is available only in local and testing environments.')"
        >
            <x-slot:actions>
                <flux:button variant="outline" icon="arrow-left" :href="route('dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:button>
            </x-slot:actions>
        </x-ui.page-header>

        <section class="space-y-4" aria-labelledby="ds-type">
            <div><h2 id="ds-type" class="text-xl font-semibold">{{ __('Typography') }}</h2><p class="ad-page-description">{{ __('A compact product scale with sentence case, clear hierarchy, and readable line lengths.') }}</p></div>
            <flux:card class="space-y-5">
                <div><p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ __('Page title · 24–32 / 1.2') }}</p><p class="ad-page-title mt-1">{{ __('Purchase request review') }}</p></div>
                <div><p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ __('Section title · 20 / 1.4') }}</p><p class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Supporting documents') }}</p></div>
                <div><p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ __('Body · 16 / 1.5') }}</p><p class="mt-1 max-w-2xl">{{ __('Keep instructions direct and specific. Use familiar words, sentence case, and enough context to help a person decide what to do next.') }}</p></div>
                <div><p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Secondary text · 14 / 1.5 — Updated 12 minutes ago') }}</p></div>
            </flux:card>
        </section>

        <section class="space-y-4" aria-labelledby="ds-colour">
            <div><h2 id="ds-colour" class="text-xl font-semibold">{{ __('Colour and status') }}</h2><p class="ad-page-description">{{ __('Semantic colour supports text labels; it never carries meaning alone.') }}</p></div>
            <div class="flex flex-wrap gap-2">
                <flux:badge color="zinc">{{ __('Draft') }}</flux:badge>
                <flux:badge color="blue">{{ __('In review') }}</flux:badge>
                <flux:badge color="lime">{{ __('Approved') }}</flux:badge>
                <flux:badge color="amber">{{ __('Needs attention') }}</flux:badge>
                <flux:badge color="red">{{ __('Rejected') }}</flux:badge>
            </div>
            <div class="grid gap-3 lg:grid-cols-2">
                <x-ui.alert variant="info" :title="__('Information')">{{ __('The workflow will begin after submission.') }}</x-ui.alert>
                <x-ui.alert variant="success" :title="__('Approved')">{{ __('The decision was recorded in the audit trail.') }}</x-ui.alert>
                <x-ui.alert variant="warning" :title="__('Action required')">{{ __('One attachment expires before the due date.') }}</x-ui.alert>
                <x-ui.alert variant="danger" :title="__('Unable to submit')">{{ __('Add a cost centre before continuing.') }}</x-ui.alert>
            </div>
        </section>

        <section class="space-y-4" aria-labelledby="ds-actions">
            <div><h2 id="ds-actions" class="text-xl font-semibold">{{ __('Actions and overlays') }}</h2><p class="ad-page-description">{{ __('One clear primary action per decision area; destructive actions use explicit language and confirmation.') }}</p></div>
            <flux:card class="flex flex-wrap items-center gap-3">
                <flux:button variant="primary">{{ __('Submit request') }}</flux:button>
                <flux:button variant="outline">{{ __('Save draft') }}</flux:button>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:modal.trigger name="design-system-danger"><flux:button variant="danger">{{ __('Delete request') }}</flux:button></flux:modal.trigger>
                <flux:button variant="primary" disabled>{{ __('Submitting') }}</flux:button>
            </flux:card>
            <flux:modal name="design-system-danger" variant="danger">
                <flux:heading size="md">{{ __('Delete this draft?') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('This removes the draft and its attachments. This action cannot be undone.') }}</flux:subheading>
                <div class="mt-6 flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger">{{ __('Delete draft') }}</flux:button>
                </div>
            </flux:modal>
        </section>

        <section class="grid gap-6 lg:grid-cols-2" aria-labelledby="ds-states">
            <div class="space-y-4"><h2 id="ds-states" class="text-xl font-semibold">{{ __('Empty and loading states') }}</h2><flux:card><x-ui.empty-state :title="__('No pending approvals')" :description="__('New requests assigned to you will appear here.')" icon="check-circle" /></flux:card></div>
            <div class="space-y-4"><h2 class="text-xl font-semibold">{{ __('Skeleton') }}</h2><flux:card class="space-y-3"><x-ui.skeleton class="h-5 w-2/5" /><x-ui.skeleton class="h-4 w-full" /><x-ui.skeleton class="h-4 w-4/5" /></flux:card></div>
        </section>

        <section class="space-y-4" aria-labelledby="ds-table">
            <div><h2 id="ds-table" class="text-xl font-semibold">{{ __('Responsive data table') }}</h2><p class="ad-page-description">{{ __('Resize below 768px to see rows become labelled cards without horizontal scrolling.') }}</p></div>
            <flux:table container:class="ad-responsive-table">
                <flux:table.columns><flux:table.column>{{ __('Reference') }}</flux:table.column><flux:table.column>{{ __('Request') }}</flux:table.column><flux:table.column>{{ __('Status') }}</flux:table.column><flux:table.column>{{ __('Updated') }}</flux:table.column></flux:table.columns>
                <flux:table.rows>
                    <flux:table.row><flux:table.cell :data-label="__('Reference')" class="font-mono text-xs">SUB-2026-0042</flux:table.cell><flux:table.cell :data-label="__('Request')">{{ __('Quarterly software renewal') }}</flux:table.cell><flux:table.cell :data-label="__('Status')"><flux:badge color="blue">{{ __('In review') }}</flux:badge></flux:table.cell><flux:table.cell :data-label="__('Updated')">{{ __('12 minutes ago') }}</flux:table.cell></flux:table.row>
                </flux:table.rows>
            </flux:table>
        </section>
    </div>
</x-layouts::app>
