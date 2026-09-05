@props([
    'heading' => '',
    'subheading' => '',
    'wide' => false,
])

<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('notifications.edit')" wire:navigate>{{ __('Notifications') }}</flux:navlist.item>
            <flux:navlist.item :href="route('out-of-office.edit')" wire:navigate>{{ __('Out of office') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('privacy.edit')" wire:navigate>{{ __('Privacy') }}</flux:navlist.item>
            <flux:navlist.item :href="route('api-tokens.edit')" wire:navigate>{{ __('API tokens') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            @can('branding.manage')
                <flux:navlist.item :href="route('settings.branding')" wire:navigate>{{ __('Branding') }}</flux:navlist.item>
            @endcan
            @can('approval_settings.manage')
                <flux:navlist.item :href="route('settings.approvals')" wire:navigate>{{ __('Approvals') }}</flux:navlist.item>
            @endcan
            @can('security_settings.manage')
                <flux:navlist.item :href="route('settings.security-privacy')" wire:navigate>{{ __('Security & privacy') }}</flux:navlist.item>
            @endcan
            @can('notification_channels.manage')
                <flux:navlist.item :href="route('settings.notification-channels')" wire:navigate>{{ __('Notification channels') }}</flux:navlist.item>
            @endcan
            @can('system_settings.manage')
                <flux:navlist.item :href="route('settings.system')" wire:navigate>{{ __('System') }}</flux:navlist.item>
            @endcan
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading }}</flux:heading>
        <flux:subheading>{{ $subheading }}</flux:subheading>

        <div @class(['mt-5 w-full', 'max-w-3xl' => $wide, 'max-w-lg' => ! $wide])>
            {{ $slot }}
        </div>
    </div>
</div>
