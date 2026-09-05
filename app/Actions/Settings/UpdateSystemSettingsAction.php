<?php

namespace App\Actions\Settings;

use App\Models\User;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class UpdateSystemSettingsAction
{
    /** @param array<string, mixed> $input */
    public function execute(array $input, User $actor): void
    {
        Gate::forUser($actor)->authorize('system_settings.manage');

        $map = [
            'mail_transport' => 'mail.transport',
            'mail_from_address' => 'mail.from_address',
            'mail_from_name' => 'mail.from_name',
            'smtp_host' => 'mail.smtp.host',
            'smtp_port' => 'mail.smtp.port',
            'smtp_scheme' => 'mail.smtp.scheme',
            'smtp_username' => 'mail.smtp.username',
            'ses_region' => 'mail.ses.region',
            'session_lifetime' => 'session.lifetime',
            'microsoft_tenant' => 'oauth.microsoft.tenant',
        ];

        foreach ($map as $field => $key) {
            if (array_key_exists($field, $input)) {
                SystemSettings::put($key, $input[$field]);
            }
        }

        SystemSettings::put('session.expire_on_close', filter_var($input['session_expire_on_close'] ?? false, FILTER_VALIDATE_BOOL));

        foreach (['google', 'microsoft', 'github'] as $provider) {
            SystemSettings::put("oauth.{$provider}.enabled", filter_var($input["{$provider}_enabled"] ?? false, FILTER_VALIDATE_BOOL));
            if (array_key_exists("{$provider}_client_id", $input)) {
                SystemSettings::put("oauth.{$provider}.client_id", $input["{$provider}_client_id"]);
            }
        }

        foreach ([
            'smtp_password', 'ses_key', 'ses_secret', 'postmark_token', 'resend_key',
            'oauth_google_secret', 'oauth_microsoft_secret', 'oauth_github_secret',
        ] as $secret) {
            $action = (string) ($input[$secret.'_action'] ?? 'keep');
            if ($action === 'clear') {
                SystemSettings::forgetSecret($secret);
            } elseif ($action === 'replace' && filled($input[$secret] ?? null)) {
                SystemSettings::putSecret($secret, (string) $input[$secret]);
            }
        }

        SystemSettings::applyRuntimeConfig();
        Mail::purge();

        activity()->causedBy($actor)->event('system-settings-updated')->withProperties([
            'mail_transport' => SystemSettings::mailTransport(),
            'session_lifetime' => SystemSettings::sessionLifetime(),
            'session_expire_on_close' => SystemSettings::expireOnClose(),
            'oauth_enabled' => collect(['google', 'microsoft', 'github'])->filter(fn (string $provider): bool => SystemSettings::oauthEnabled($provider))->values()->all(),
        ])->log('System settings updated');
        Log::info('System settings updated.', ['actor_id' => $actor->id]);
    }
}
