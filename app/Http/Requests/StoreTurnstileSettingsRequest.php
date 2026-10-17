<?php

namespace App\Http\Requests;

use App\DTOs\TurnstileSettingsData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Cloudflare Turnstile on Settings → Security & privacy (RV-LAR-09). */
class StoreTurnstileSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('security_settings.manage') === true;
    }

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'site_key' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'clear' => ['sometimes', 'boolean'],
        ];
    }

    public function settings(): TurnstileSettingsData
    {
        return new TurnstileSettingsData(
            enabled: $this->boolean('enabled'),
            siteKey: $this->string('site_key')->toString(),
            secret: $this->filled('secret') ? $this->string('secret')->toString() : null,
            clear: $this->boolean('clear'),
        );
    }
}
