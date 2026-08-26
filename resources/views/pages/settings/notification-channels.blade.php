<?php

use App\Actions\Settings\FetchWhatsAppTemplatesAction;
use App\Actions\Settings\SaveNotificationEmailTemplateAction;
use App\Actions\Settings\SaveUserChannelIdentityAction;
use App\Actions\Settings\SaveWhatsAppNotificationMapAction;
use App\Actions\Settings\TestNotificationChannelAction;
use App\Actions\Settings\UpdateNotificationChannelFlagsAction;
use App\Enums\NotificationChannelKey;
use App\Enums\NotificationKind;
use App\Models\NotificationDelivery;
use App\Models\NotificationEmailTemplate;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappNotificationMap;
use App\Services\Notifications\ChannelSettings;
use App\Services\Notifications\NotificationCopy;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Notification channels')] class extends Component {
    #[Url]
    public string $tab = 'overview';

    public bool $teams_enabled = false;
    public bool $slack_enabled = false;
    public bool $whatsapp_enabled = false;
    public bool $telegram_enabled = false;

    public int $mapping_user_id = 0;
    public string $mapping_channel = 'slack';
    public string $mapping_external_id = '';
    public string $peopleSearch = '';

    public string $templateKind = 'action_required';
    public string $templateSubject = '';
    public string $templateBody = '';

    public string $whatsappKind = 'action_required';
    public string $whatsappTemplate = '';
    public string $whatsappLanguage = 'en';
    public string $whatsappBindings = '';

    public function mount(ChannelSettings $settings, NotificationCopy $copy): void
    {
        $this->authorize('settings.manage');
        $this->teams_enabled = $settings->isEnabled(NotificationChannelKey::Teams);
        $this->slack_enabled = $settings->isEnabled(NotificationChannelKey::Slack);
        $this->whatsapp_enabled = $settings->isEnabled(NotificationChannelKey::WhatsApp);
        $this->telegram_enabled = $settings->isEnabled(NotificationChannelKey::Telegram);
        $this->loadTemplate($copy);
    }

    public function updatedTemplateKind(NotificationCopy $copy): void
    {
        $this->loadTemplate($copy);
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
        $result = $test->execute(NotificationChannelKey::from($channel));
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
        try {
            $save->execute(
                User::query()->assignable()->findOrFail($validated['mapping_user_id']),
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

    public function unlink(int $userId, string $channel, SaveUserChannelIdentityAction $save): void
    {
        $this->authorize('settings.manage');
        $save->execute(User::query()->assignable()->findOrFail($userId), NotificationChannelKey::from($channel), null);
        Flux::toast('Mapping removed.', variant: 'success');
    }

    public function saveTemplate(SaveNotificationEmailTemplateAction $save, NotificationCopy $copy): void
    {
        $this->authorize('settings.manage');
        try {
            $save->execute(NotificationKind::from($this->templateKind), $this->templateSubject, $this->templateBody, auth()->user());
        } catch (\InvalidArgumentException $exception) {
            $this->addError('templateBody', $exception->getMessage());

            return;
        }
        $this->loadTemplate($copy);
        Flux::toast('Email template saved.', variant: 'success');
    }

    public function restoreTemplate(SaveNotificationEmailTemplateAction $save, NotificationCopy $copy): void
    {
        $this->authorize('settings.manage');
        $save->restore(NotificationKind::from($this->templateKind));
        $this->loadTemplate($copy);
        Flux::toast('Default copy restored.', variant: 'success');
    }

    public function fetchWhatsApp(FetchWhatsAppTemplatesAction $fetch): void
    {
        $this->authorize('settings.manage');
        try {
            $count = $fetch->execute();
            Flux::toast('Fetched '.$count.' WhatsApp templates.', variant: 'success');
        } catch (\Throwable $exception) {
            Flux::toast($exception->getMessage(), variant: 'danger');
        }
    }

    public function saveWhatsAppMap(SaveWhatsAppNotificationMapAction $save): void
    {
        $this->authorize('settings.manage');
        $bindings = [];
        foreach (preg_split('/\r\n|\r|\n/', $this->whatsappBindings) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$slot, $placeholder] = array_map('trim', explode('=', $line, 2));
            $bindings[$slot] = $placeholder;
        }
        try {
            $save->execute(NotificationKind::from($this->whatsappKind), $this->whatsappTemplate, $this->whatsappLanguage, $bindings);
            Flux::toast('WhatsApp mapping saved.', variant: 'success');
        } catch (\InvalidArgumentException $exception) {
            $this->addError('whatsappBindings', $exception->getMessage());
        }
    }

    private function loadTemplate(NotificationCopy $copy): void
    {
        $kind = NotificationKind::from($this->templateKind);
        $stored = NotificationEmailTemplate::query()->where('kind', $kind->value)->first();
        $defaults = $copy->defaults($kind);
        $this->templateSubject = $stored->subject ?? $defaults['subject'];
        $this->templateBody = $stored->body ?? $defaults['body'];
    }
} ?>

@php
    $settings = app(\App\Services\Notifications\ChannelSettings::class);
    $copy = app(\App\Services\Notifications\NotificationCopy::class);
    $tabs = [
        'overview' => __('Overview'),
        'email' => __('Email copy'),
        'channels' => __('Chat channels'),
        'people' => __('People'),
        'delivery' => __('Delivery'),
    ];
    $people = \App\Models\User::query()
        ->assignable()
        ->when($peopleSearch !== '', fn ($query) => $query->where('name', 'like', '%'.$peopleSearch.'%')->orWhere('email', 'like', '%'.$peopleSearch.'%'))
        ->orderBy('name')
        ->limit(50)
        ->get(['id', 'name', 'email', 'phone', 'teams_user_id', 'slack_user_id', 'telegram_user_id']);
    $deliveries = \App\Models\NotificationDelivery::query()->with('user:id,name')->latest('id')->limit(40)->get();
    $whatsappCatalog = \App\Models\WhatsappMessageTemplate::query()->orderBy('name')->get();
    $preview = $copy->render($templateKind, [
        'recipient_name' => 'Ada Lovelace',
        'reference_number' => 'SUB-2026-00001',
        'title' => 'Leave request',
        'submitter_name' => 'Charles Babbage',
        'priority' => 'normal',
        'submission_url' => url('/submissions/1'),
        'approval_url' => url('/email-approvals/example'),
        'decision' => 'approved',
        'comment' => 'Looks good.',
        'count' => '3',
    ]);
    $channelMeta = [
        'teams' => ['label' => __('Microsoft Teams'), 'enabled' => $teams_enabled],
        'slack' => ['label' => __('Slack'), 'enabled' => $slack_enabled],
        'whatsapp' => ['label' => __('WhatsApp'), 'enabled' => $whatsapp_enabled],
        'telegram' => ['label' => __('Telegram'), 'enabled' => $telegram_enabled],
    ];
@endphp

<x-pages::settings.layout :heading="__('Notification channels')" :subheading="__('In-app and email stay on. Optional chat is skip-not-fail.')" :wide="true">
    <div class="space-y-6">
        <div class="flex items-center gap-1">
            <x-ui.help-link article="notification-channels" />
        </div>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <div class="flex gap-1 overflow-x-auto pb-1" role="tablist" aria-label="{{ __('Notification channel sections') }}">
            @foreach ($tabs as $key => $label)
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $key }}')"
                    @class(['rounded-full px-3 py-1.5 text-sm whitespace-nowrap', 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $tab === $key, 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' => $tab !== $key])
                    role="tab"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                >{{ $label }}</button>
            @endforeach
        </div>

        @if ($tab === 'overview')
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:card>
                    <flux:heading size="sm">{{ __('Always on') }}</flux:heading>
                    <p class="mt-2 text-sm text-zinc-500">{{ __('Inbox and email. People choose Immediate, digest, or in-app only on their own Notifications page.') }}</p>
                </flux:card>
                @foreach ($channelMeta as $key => $meta)
                    @php
                        $channelKey = \App\Enums\NotificationChannelKey::from($key);
                        $ready = $meta['enabled'] && $settings->hasCredentials($channelKey);
                    @endphp
                    <flux:card>
                        <div class="flex items-start justify-between gap-2">
                            <flux:heading size="sm">{{ $meta['label'] }}</flux:heading>
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $ready ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800' }}">
                                {{ $ready ? __('Ready') : ($meta['enabled'] ? __('Needs credentials') : __('Off')) }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm text-zinc-500">{{ $settings->lastError($channelKey) ?: ($settings->lastTestedAt($channelKey) ? __('Last test :time', ['time' => $settings->lastTestedAt($channelKey)]) : __('Not tested yet.')) }}</p>
                    </flux:card>
                @endforeach
            </div>
        @endif

        @if ($tab === 'email')
            <flux:callout icon="information-circle">{{ __('Generic copy is used until you save an override. Action required must keep the approval_url merge field.') }}</flux:callout>
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="space-y-4">
                    <flux:select wire:model.live="templateKind" :label="__('Notification')">
                        @foreach (\App\Enums\NotificationKind::cases() as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="templateSubject" :label="__('Subject')" />
                    <flux:textarea wire:model="templateBody" :label="__('Body')" rows="8" />
                    <p class="text-xs text-zinc-500">{{ __('Merge fields: :fields', ['fields' => implode(', ', \App\Enums\NotificationKind::from($templateKind)->mergeFields())]) }}</p>
                    <flux:error name="templateBody" />
                    <div class="flex flex-wrap gap-3">
                        <flux:button variant="primary" wire:click="saveTemplate">{{ __('Save copy') }}</flux:button>
                        <flux:button variant="ghost" wire:click="restoreTemplate">{{ __('Restore default') }}</flux:button>
                    </div>
                </div>
                <flux:card>
                    <flux:heading size="sm">{{ __('Preview') }}</flux:heading>
                    <p class="mt-3 font-medium">{{ $preview->subject }}</p>
                    <div class="mt-2 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                        @foreach ($preview->lines as $line)
                            <p>{{ $line }}</p>
                        @endforeach
                    </div>
                </flux:card>
            </div>
        @endif

        @if ($tab === 'channels')
            <form wire:submit="saveFlags" class="space-y-3">
                <div class="grid gap-2 sm:grid-cols-2">
                    <flux:checkbox wire:model="teams_enabled" :label="__('Microsoft Teams')" />
                    <flux:checkbox wire:model="slack_enabled" :label="__('Slack')" />
                    <flux:checkbox wire:model="whatsapp_enabled" :label="__('WhatsApp')" />
                    <flux:checkbox wire:model="telegram_enabled" :label="__('Telegram')" />
                </div>
                <flux:button variant="primary" type="submit" data-test="save-channel-flags">{{ __('Save channel options') }}</flux:button>
            </form>

            @foreach ([
                'teams' => [['name' => 'app_id', 'label' => __('App ID'), 'type' => 'text'], ['name' => 'app_password', 'label' => __('App password'), 'type' => 'password'], ['name' => 'tenant_id', 'label' => __('Tenant ID'), 'type' => 'text']],
                'slack' => [['name' => 'bot_token', 'label' => __('Bot token'), 'type' => 'password'], ['name' => 'signing_secret', 'label' => __('Signing secret'), 'type' => 'password']],
                'whatsapp' => [['name' => 'waba_id', 'label' => __('Business account ID'), 'type' => 'text'], ['name' => 'phone_number_id', 'label' => __('Phone number ID'), 'type' => 'text'], ['name' => 'access_token', 'label' => __('Access token'), 'type' => 'password']],
                'telegram' => [['name' => 'bot_token', 'label' => __('Bot token'), 'type' => 'password'], ['name' => 'webhook_secret', 'label' => __('Webhook secret'), 'type' => 'password']],
            ] as $key => $fields)
                @php $channelKey = \App\Enums\NotificationChannelKey::from($key); @endphp
                <flux:card class="mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading size="sm">{{ $channelKey->label() }}</flux:heading>
                        <flux:button type="button" size="sm" wire:click="test('{{ $key }}')" data-test="test-channel-{{ $key }}">{{ __('Test connection') }}</flux:button>
                    </div>
                    <p class="mt-1 text-sm text-zinc-500">
                        {{ $settings->hasCredentials($channelKey) ? __('Credentials stored. Blank fields keep the current secret.') : __('No credentials yet.') }}
                        @if ($settings->lastError($channelKey))
                            <span class="text-red-600">{{ $settings->lastError($channelKey) }}</span>
                        @endif
                    </p>
                    <form method="POST" action="{{ route('settings.notification-channels.credentials') }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="channel" value="{{ $key }}">
                        @foreach ($fields as $field)
                            <flux:input name="{{ $field['name'] }}" :label="$field['label']" :type="$field['type']" autocomplete="off" />
                        @endforeach
                        <div class="flex flex-wrap gap-3">
                            <flux:button type="submit" variant="primary">{{ __('Save credentials') }}</flux:button>
                            <flux:button type="submit" name="clear" value="1" variant="danger">{{ __('Clear credentials') }}</flux:button>
                        </div>
                    </form>
                    @if ($key === 'telegram')
                        <p class="mt-3 text-sm text-zinc-500">{{ __('Webhook: :url', ['url' => route('integrations.telegram.webhook')]) }}</p>
                    @endif
                    @if ($key === 'slack')
                        <p class="mt-3 text-sm text-zinc-500">{{ __('Interactivity URL: :url', ['url' => route('integrations.slack.interactions')]) }}</p>
                    @endif
                </flux:card>
            @endforeach

            <flux:card class="mt-4">
                <flux:heading size="sm">{{ __('WhatsApp template mapping') }}</flux:heading>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Fetch approved Meta templates, then bind each notification kind. Unmapped kinds skip WhatsApp.') }}</p>
                <flux:button class="mt-3" wire:click="fetchWhatsApp">{{ __('Fetch templates') }}</flux:button>
                <div class="mt-4 space-y-3">
                    <flux:select wire:model="whatsappKind" :label="__('Kind')">
                        @foreach (\App\Enums\NotificationKind::cases() as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="whatsappTemplate" :label="__('Approved template')">
                        <option value="">{{ __('Choose') }}</option>
                        @foreach ($whatsappCatalog->filter(fn ($row) => $row->isApproved()) as $row)
                            <option value="{{ $row->name }}">{{ $row->name }} ({{ $row->language }})</option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="whatsappLanguage" :label="__('Language')" />
                    <flux:textarea wire:model="whatsappBindings" :label="__('Bindings')" :description="__('One per line, for example recipient_name=1')" rows="4" />
                    <flux:error name="whatsappBindings" />
                    <flux:button variant="primary" wire:click="saveWhatsAppMap">{{ __('Save mapping') }}</flux:button>
                </div>
            </flux:card>
        @endif

        @if ($tab === 'people')
            <p class="text-sm text-zinc-500">{{ __('Optional channels only notify mapped people. Leave the ID blank to unlink. WhatsApp uses the phone on the user record.') }}</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:select wire:model="mapping_user_id" :label="__('Person')">
                    <option value="0">{{ __('Choose a person') }}</option>
                    @foreach ($people as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="mapping_channel" :label="__('Channel')">
                    <option value="teams">{{ __('Teams') }}</option>
                    <option value="slack">{{ __('Slack') }}</option>
                    <option value="telegram">{{ __('Telegram') }}</option>
                </flux:select>
            </div>
            <flux:input class="mt-3" wire:model="mapping_external_id" :label="__('External user ID')" :description="__('Leave blank to unlink.')" />
            <flux:error name="mapping_user_id" />
            <flux:error name="mapping_external_id" />
            <flux:button class="mt-3" variant="primary" wire:click="saveMapping" data-test="save-channel-mapping">{{ __('Save mapping') }}</flux:button>
            <flux:input class="mt-6" wire:model.live.debounce.300ms="peopleSearch" :label="__('Find people')" />
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-zinc-500">
                            <th class="py-2 pr-4">{{ __('Person') }}</th>
                            <th class="py-2 pr-4">{{ __('Teams') }}</th>
                            <th class="py-2 pr-4">{{ __('Slack') }}</th>
                            <th class="py-2 pr-4">{{ __('Telegram') }}</th>
                            <th class="py-2">{{ __('Phone') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($people as $user)
                            <tr class="border-t border-zinc-200 dark:border-zinc-800">
                                <td class="py-2 pr-4">{{ $user->name }}</td>
                                <td class="py-2 pr-4">
                                    <span class="font-mono text-xs">{{ $user->teams_user_id ?: '—' }}</span>
                                    @if ($user->teams_user_id)
                                        <button type="button" class="ml-2 text-red-600" wire:click="unlink({{ $user->id }}, 'teams')">{{ __('Remove') }}</button>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="font-mono text-xs">{{ $user->slack_user_id ?: '—' }}</span>
                                    @if ($user->slack_user_id)
                                        <button type="button" class="ml-2 text-red-600" wire:click="unlink({{ $user->id }}, 'slack')">{{ __('Remove') }}</button>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="font-mono text-xs">{{ $user->telegram_user_id ?: '—' }}</span>
                                    @if ($user->telegram_user_id)
                                        <button type="button" class="ml-2 text-red-600" wire:click="unlink({{ $user->id }}, 'telegram')">{{ __('Remove') }}</button>
                                    @endif
                                </td>
                                <td class="py-2 font-mono text-xs">{{ $user->phone ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($tab === 'delivery')
            <p class="text-sm text-zinc-500">{{ __('Optional-channel sends during quiet hours wait, then retry. In-app is not delayed. Failed action-required chat messages retry up to three times.') }}</p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-zinc-500">
                            <th class="py-2 pr-4">{{ __('When') }}</th>
                            <th class="py-2 pr-4">{{ __('Person') }}</th>
                            <th class="py-2 pr-4">{{ __('Channel') }}</th>
                            <th class="py-2 pr-4">{{ __('Kind') }}</th>
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                            <th class="py-2">{{ __('Detail') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($deliveries as $delivery)
                            <tr class="border-t border-zinc-200 dark:border-zinc-800">
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $delivery->created_at?->format('d M H:i') }}</td>
                                <td class="py-2 pr-4">{{ $delivery->user?->name }}</td>
                                <td class="py-2 pr-4">{{ $delivery->channel }}</td>
                                <td class="py-2 pr-4">{{ $delivery->kind }}</td>
                                <td class="py-2 pr-4">{{ $delivery->status->value }}</td>
                                <td class="py-2 text-zinc-500">{{ $delivery->last_error ?: ($delivery->attempts.' attempt(s)') }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-6 text-zinc-500" colspan="6">{{ __('No optional-channel deliveries yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-pages::settings.layout>
