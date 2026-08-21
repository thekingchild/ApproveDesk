<?php

use App\Enums\NotificationChannelKey;
use App\Enums\NotificationFrequency;
use App\Models\Setting;
use App\Services\Notifications\ChannelSettings;
use App\Services\Notifications\WebPushService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification settings')] class extends Component {
    public string $frequency = NotificationFrequency::Immediate->value;

    public bool $pushEnabled = false;

    public ?string $telegramLink = null;

    public bool $telegramLinked = false;

    public bool $telegramAvailable = false;

    public string $vapidPublicKey = '';

    public function mount(ChannelSettings $channels, WebPushService $push): void
    {
        $user = Auth::user();
        $this->frequency = $user->notification_frequency->value;
        $this->pushEnabled = $user->pushSubscriptions()->exists();
        $this->vapidPublicKey = $push->publicKey();
        $this->telegramAvailable = $channels->isEnabled(NotificationChannelKey::Telegram) && $channels->hasCredentials(NotificationChannelKey::Telegram);
        $this->telegramLinked = filled($user->telegram_user_id);
        $this->telegramLink = $this->telegramAvailable ? $this->buildTelegramLink() : null;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'frequency' => ['required', Rule::enum(NotificationFrequency::class)],
        ]);

        Auth::user()->update([
            'notification_frequency' => NotificationFrequency::from($validated['frequency']),
            'last_notification_digest_at' => null,
        ]);

        Flux::toast('Notification preferences saved.', variant: 'success');
    }

    public function unlinkTelegram(): void
    {
        Auth::user()->forceFill(['telegram_user_id' => null])->save();
        $this->telegramLinked = false;
        $this->telegramLink = $this->buildTelegramLink();
        Flux::toast('Telegram unlinked.', variant: 'success');
    }

    private function buildTelegramLink(): ?string
    {
        $username = Setting::get('notifications.telegram.bot_username');
        if (! is_string($username) || $username === '') {
            return null;
        }

        $token = Str::random(32);
        Cache::put('telegram-link:'.$token, Auth::id(), now()->addHours(2));

        return 'https://t.me/'.$username.'?start='.$token;
    }
}; ?>

<x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Choose when emails arrive. Optional chat channels follow organisation settings.')">
    <form wire:submit="save" class="space-y-6">
        <flux:radio.group wire:model="frequency" :label="__('Email delivery')">
            <flux:radio value="immediate" :label="__('Immediately')" :description="__('Send each approval update as it happens.')" />
            <flux:radio value="daily" :label="__('Daily digest')" :description="__('Combine updates into one daily email.')" />
            <flux:radio value="weekly" :label="__('Weekly digest')" :description="__('Combine updates into one email every seven days.')" />
            <flux:radio value="in_app_only" :label="__('In-app only')" :description="__('Keep updates in ApproveDesk without workflow emails. This does not mute Teams, Slack, WhatsApp, Telegram, or browser push.')" />
        </flux:radio.group>
        <flux:error name="frequency" />
        <flux:button type="submit" variant="primary">{{ __('Save preferences') }}</flux:button>
    </form>

    <div class="mt-10 space-y-4">
        <flux:heading size="sm">{{ __('This device') }}</flux:heading>
        <p class="text-sm text-zinc-500">{{ __('Install ApproveDesk and allow browser notifications if you want alerts when this tab is closed.') }}</p>
        <div class="flex flex-wrap gap-3">
            <flux:button type="button" id="install-app-button">{{ __('Install app') }}</flux:button>
            <flux:button
                type="button"
                x-data
                x-on:click="window.approveDeskPush.subscribe(@js($vapidPublicKey)).then(() => window.location.reload())"
            >{{ $pushEnabled ? __('Browser notifications on') : __('Enable browser notifications') }}</flux:button>
            @if ($pushEnabled)
                <flux:button type="button" variant="ghost" x-data x-on:click="window.approveDeskPush.unsubscribe().then(() => window.location.reload())">{{ __('Turn off browser notifications') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($telegramAvailable)
        <div class="mt-10 space-y-3">
            <flux:heading size="sm">{{ __('Telegram') }}</flux:heading>
            @if ($telegramLinked)
                <p class="text-sm text-zinc-500">{{ __('This account is linked. Unlink to stop Telegram messages.') }}</p>
                <flux:button type="button" variant="danger" wire:click="unlinkTelegram">{{ __('Unlink Telegram') }}</flux:button>
            @elseif ($telegramLink)
                <p class="text-sm text-zinc-500">{{ __('Open the bot, tap Start, and ApproveDesk will store your Telegram user id.') }}</p>
                <flux:button :href="$telegramLink" target="_blank">{{ __('Link Telegram') }}</flux:button>
            @else
                <p class="text-sm text-zinc-500">{{ __('Ask an administrator to test the Telegram connection so the bot username is known.') }}</p>
            @endif
        </div>
    @endif
</x-pages::settings.layout>

<script>
    document.getElementById('install-app-button')?.addEventListener('click', async () => {
        if (window.approveDeskInstallPrompt) {
            window.approveDeskInstallPrompt.prompt()
            return
        }
        alert(@json(__('Use your browser’s Install or Add to Home Screen command if the prompt does not appear.')))
    })
</script>
