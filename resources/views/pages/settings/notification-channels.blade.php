<?php

use App\Actions\Settings\SaveUserChannelIdentityAction;
use App\Actions\Settings\TestNotificationChannelAction;
use App\Actions\Settings\UpdateNotificationChannelFlagsAction;
use App\Enums\NotificationChannelKey;
use App\Models\User;
use App\Services\Notifications\ChannelSettings;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification channels')] class extends Component {
    public bool $teams_enabled = false;

    public bool $slack_enabled = false;

    public bool $whatsapp_enabled = false;

    public bool $telegram_enabled = false;

    public int $mapping_user_id = 0;

    public string $mapping_channel = 'slack';

    public string $mapping_external_id = '';

    public function mount(ChannelSettings $settings): void
    {
        $this->authorize('settings.manage');
        $this->teams_enabled = $settings->isEnabled(NotificationChannelKey::Teams);
        $this->slack_enabled = $settings->isEnabled(NotificationChannelKey::Slack);
        $this->whatsapp_enabled = $settings->isEnabled(NotificationChannelKey::WhatsApp);
        $this->telegram_enabled = $settings->isEnabled(NotificationChannelKey::Telegram);
    }

    public function saveFlags(UpdateNotificationChannelFlagsAction $update): void
    {
        $this->authorize('settings.manage');

        $update->execute([
            'teams' => $this->teams_enabled,
            'slack' => $this->slack_enabled,
            'whatsapp' => $this->whatsapp_enabled,
            'telegram' => $this->telegram_enabled,
        ]);

        Flux::toast('Channel options saved.', variant: 'success');
    }

    public function test(string $channel, TestNotificationChannelAction $test): void
    {
        $this->authorize('settings.manage');
        $key = NotificationChannelKey::from($channel);
        $result = $test->execute($key);

        Flux::toast($result->message, variant: $result->ok ? 'success' : 'danger');
    }

    public function saveMapping(SaveUserChannelIdentityAction $save): void
    {
        $this->authorize('settings.manage');

        $validated = $this->validate([
            'mapping_user_id' => ['required', 'integer', 'exists:users,id'],
            'mapping_channel' => ['required', 'in:teams,slack,telegram'],
            'mapping_external_id' => ['nullable', 'string', 'max:64'],
        ]);

        $user = User::query()->findOrFail($validated['mapping_user_id']);

        try {
            $save->execute(
                $user,
                NotificationChannelKey::from($validated['mapping_channel']),
                $validated['mapping_external_id'] === '' ? null : $validated['mapping_external_id'],
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addError('mapping_external_id', $exception->getMessage());

            return;
        }

        $this->mapping_external_id = '';
        Flux::toast('Identity mapping saved.', variant: 'success');
    }
} ?>

@php
    $settings = app(\App\Services\Notifications\ChannelSettings::class);
    $users = \App\Models\User::query()->orderBy('name')->limit(200)->get(['id', 'name', 'email', 'phone', 'teams_user_id', 'slack_user_id', 'telegram_user_id']);
@endphp

<x-pages::settings.layout :heading="__('Notification channels')" :subheading="__('Turn on optional delivery. In-app and email always stay on.')" :wide="true">
    <div class="space-y-8">
        <div class="mb-4 flex items-center gap-1">
            <x-ui.help-link article="notification-channels" />
        </div>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <flux:callout variant="info" icon="information-circle">
            {{ __('In-app notifications and email are always available. Optional channels skip when they are off, missing credentials, or the person is not mapped. They never stop a workflow.') }}
        </flux:callout>

        <div class="space-y-4">
            <flux:heading size="sm">{{ __('Enable optional channels') }}</flux:heading>
            <flux:checkbox wire:model="teams_enabled" :label="__('Microsoft Teams')" />
            <flux:checkbox wire:model="slack_enabled" :label="__('Slack')" />
            <flux:checkbox wire:model="whatsapp_enabled" :label="__('WhatsApp')" />
            <flux:checkbox wire:model="telegram_enabled" :label="__('Telegram')" />
            <flux:button variant="primary" wire:click="saveFlags" data-test="save-channel-flags">{{ __('Save channel options') }}</flux:button>
        </div>

        <flux:separator />

        @foreach ([
            'teams' => [
                'label' => __('Microsoft Teams'),
                'enabled' => $teams_enabled,
                'fields' => [
                    ['name' => 'app_id', 'label' => __('App ID'), 'type' => 'text'],
                    ['name' => 'app_password', 'label' => __('App password'), 'type' => 'password'],
                    ['name' => 'tenant_id', 'label' => __('Tenant ID (optional)'), 'type' => 'text'],
                ],
            ],
            'slack' => [
                'label' => __('Slack'),
                'enabled' => $slack_enabled,
                'fields' => [
                    ['name' => 'bot_token', 'label' => __('Bot token'), 'type' => 'password'],
                    ['name' => 'signing_secret', 'label' => __('Signing secret'), 'type' => 'password'],
                ],
            ],
            'whatsapp' => [
                'label' => __('WhatsApp'),
                'enabled' => $whatsapp_enabled,
                'fields' => [
                    ['name' => 'waba_id', 'label' => __('WhatsApp business account ID'), 'type' => 'text'],
                    ['name' => 'phone_number_id', 'label' => __('Phone number ID'), 'type' => 'text'],
                    ['name' => 'access_token', 'label' => __('Access token'), 'type' => 'password'],
                ],
            ],
            'telegram' => [
                'label' => __('Telegram'),
                'enabled' => $telegram_enabled,
                'fields' => [
                    ['name' => 'bot_token', 'label' => __('Bot token'), 'type' => 'password'],
                    ['name' => 'webhook_secret', 'label' => __('Webhook secret (optional)'), 'type' => 'password'],
                ],
            ],
        ] as $key => $channel)
            @php
                $channelKey = \App\Enums\NotificationChannelKey::from($key);
                $hasCredentials = $settings->hasCredentials($channelKey);
                $lastTest = $settings->lastTestedAt($channelKey);
                $lastError = $settings->lastError($channelKey);
            @endphp
            <flux:card>
                <flux:heading size="sm">{{ $channel['label'] }}</flux:heading>
                <p class="mt-1 text-sm text-zinc-500">
                    @if ($hasCredentials)
                        {{ __('Credentials are stored. Leave a field blank to keep the current secret.') }}
                    @else
                        {{ __('No credentials stored yet.') }}
                    @endif
                    @if ($lastTest)
                        {{ __('Last successful test: :time', ['time' => $lastTest]) }}
                    @endif
                </p>
                @if ($lastError)
                    <flux:callout variant="danger" class="mt-3">{{ $lastError }}</flux:callout>
                @endif

                <form method="POST" action="{{ route('settings.notification-channels.credentials') }}" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="channel" value="{{ $key }}">
                    @foreach ($channel['fields'] as $field)
                        <flux:input
                            name="{{ $field['name'] }}"
                            :label="$field['label']"
                            :type="$field['type']"
                            autocomplete="off"
                        />
                    @endforeach
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button type="submit" variant="primary">{{ __('Save credentials') }}</flux:button>
                        <flux:button type="submit" name="clear" value="1" variant="danger">{{ __('Clear credentials') }}</flux:button>
                        <flux:button type="button" wire:click="test('{{ $key }}')" data-test="test-channel-{{ $key }}">{{ __('Test connection') }}</flux:button>
                    </div>
                </form>

                @if ($key === 'telegram')
                    <p class="mt-3 text-sm text-zinc-500">
                        {{ __('Webhook URL (HTTPS required): :url', ['url' => route('integrations.telegram.webhook')]) }}
                    </p>
                @endif
                @if ($key === 'whatsapp')
                    <p class="mt-3 text-sm text-zinc-500">
                        {{ __('WhatsApp messages wait for template mapping in a later update. Until then, a mapped phone number still receives in-app and email notifications.') }}
                    </p>
                @endif
            </flux:card>
        @endforeach

        <flux:separator />

        <div class="space-y-4">
            <flux:heading size="sm">{{ __('Identity mapping') }}</flux:heading>
            <p class="text-sm text-zinc-500">{{ __('Optional channels only notify people who are mapped. WhatsApp uses the phone number on the user record. SMS and web push are not administrator options yet.') }}</p>

            <flux:select wire:model="mapping_user_id" :label="__('Person')">
                <option value="0">{{ __('Choose a person') }}</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                @endforeach
            </flux:select>
            <flux:select wire:model="mapping_channel" :label="__('Channel')">
                <option value="teams">{{ __('Microsoft Teams') }}</option>
                <option value="slack">{{ __('Slack') }}</option>
                <option value="telegram">{{ __('Telegram') }}</option>
            </flux:select>
            <flux:input wire:model="mapping_external_id" :label="__('External user ID')" :description="__('Leave blank to unlink.')" />
            <flux:button variant="primary" wire:click="saveMapping" data-test="save-channel-mapping">{{ __('Save mapping') }}</flux:button>
            <flux:error name="mapping_user_id" />
            <flux:error name="mapping_external_id" />

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-zinc-500">
                            <th class="py-2 pr-4">{{ __('Person') }}</th>
                            <th class="py-2 pr-4">{{ __('Teams') }}</th>
                            <th class="py-2 pr-4">{{ __('Slack') }}</th>
                            <th class="py-2 pr-4">{{ __('Telegram') }}</th>
                            <th class="py-2">{{ __('WhatsApp phone') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            @if ($user->teams_user_id || $user->slack_user_id || $user->telegram_user_id || $user->phone)
                                <tr class="border-t border-zinc-200 dark:border-zinc-800">
                                    <td class="py-2 pr-4">{{ $user->name }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $user->teams_user_id ?: '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $user->slack_user_id ?: '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $user->telegram_user_id ?: '—' }}</td>
                                    <td class="py-2 font-mono text-xs">{{ $user->phone ?: '—' }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('Email template editing, WhatsApp template mapping, and Teams/Slack approve buttons are later 8.2 work. SMS and web push stay in the channel interface but have no administrator options yet.') }}
        </flux:callout>
    </div>
</x-pages::settings.layout>
