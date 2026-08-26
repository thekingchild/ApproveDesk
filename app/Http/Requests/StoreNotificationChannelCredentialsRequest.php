<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannelKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationChannelCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('notification_channels.manage') === true;
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        $channel = NotificationChannelKey::tryFrom((string) $this->input('channel'));

        $rules = [
            'channel' => ['required', 'string', Rule::in(['teams', 'slack', 'whatsapp', 'telegram'])],
            'clear' => ['sometimes', 'boolean'],
        ];

        return array_merge($rules, match ($channel) {
            NotificationChannelKey::Teams => [
                'app_id' => ['nullable', 'string', 'max:128'],
                'app_password' => ['nullable', 'string', 'max:255'],
                'tenant_id' => ['nullable', 'string', 'max:128'],
            ],
            NotificationChannelKey::Slack => [
                'bot_token' => ['nullable', 'string', 'max:255'],
                'signing_secret' => ['nullable', 'string', 'max:255'],
            ],
            NotificationChannelKey::WhatsApp => [
                'waba_id' => ['nullable', 'string', 'max:64'],
                'phone_number_id' => ['nullable', 'string', 'max:64'],
                'access_token' => ['nullable', 'string', 'max:4096'],
            ],
            NotificationChannelKey::Telegram => [
                'bot_token' => ['nullable', 'string', 'max:255'],
                'webhook_secret' => ['nullable', 'string', 'max:128'],
            ],
            default => [],
        });
    }

    public function channel(): NotificationChannelKey
    {
        return NotificationChannelKey::from((string) $this->input('channel', 'slack'));
    }
}
