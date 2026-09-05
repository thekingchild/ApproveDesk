<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system_settings.manage') === true;
    }

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        $transports = ['smtp', 'ses', 'postmark', 'resend', 'sendmail'];
        if (config('mail.allow_log_transport')) {
            $transports[] = 'log';
        }

        $rules = [
            'mail_transport' => ['required', Rule::in($transports)],
            'mail_from_address' => ['required', 'email:rfc', 'max:255'],
            'mail_from_name' => ['required', 'string', 'max:255'],
            'smtp_host' => ['exclude_unless:mail_transport,smtp', 'required', 'string', 'max:255'],
            'smtp_port' => ['exclude_unless:mail_transport,smtp', 'required', 'integer', 'min:1', 'max:65535'],
            'smtp_scheme' => ['exclude_unless:mail_transport,smtp', 'nullable', Rule::in(['tls', 'smtps', ''])],
            'smtp_username' => ['exclude_unless:mail_transport,smtp', 'nullable', 'string', 'max:255'],
            'ses_region' => ['exclude_unless:mail_transport,ses', 'required', 'regex:/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', 'max:32'],
            'session_lifetime' => ['required', 'integer', 'min:5', 'max:1440'],
            'session_expire_on_close' => ['required', 'boolean'],
            'google_enabled' => ['required', 'boolean'],
            'microsoft_enabled' => ['required', 'boolean'],
            'github_enabled' => ['required', 'boolean'],
            'google_client_id' => ['nullable', 'string', 'max:512'],
            'microsoft_client_id' => ['nullable', 'string', 'max:512'],
            'github_client_id' => ['nullable', 'string', 'max:512'],
            'microsoft_tenant' => ['nullable', 'regex:/^[A-Za-z0-9.-]+$/', 'max:128'],
        ];

        foreach ([
            'smtp_password' => 1024,
            'ses_key' => 1024,
            'ses_secret' => 1024,
            'postmark_token' => 1024,
            'resend_key' => 1024,
            'oauth_google_secret' => 2048,
            'oauth_microsoft_secret' => 2048,
            'oauth_github_secret' => 2048,
        ] as $secret => $max) {
            $rules[$secret.'_action'] = ['sometimes', Rule::in(['keep', 'replace', 'clear'])];
            $rules[$secret] = ['nullable', 'string', "max:{$max}", Rule::requiredIf($this->input($secret.'_action') === 'replace')];
        }

        return $rules;
    }
}
