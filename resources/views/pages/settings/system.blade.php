<x-layouts::app :title="__('System settings')">
    <section class="w-full">
        @include('partials.settings-heading')

        <x-pages::settings.layout :heading="__('System settings')" :subheading="__('Outbound email, browser sessions, and social sign-in.')" :wide="true">
            <div class="mb-4">
                <x-ui.help-link article="system-settings" />
            </div>
            @if (session('status'))
                <flux:callout variant="success" class="mb-6">{{ session('status') }}</flux:callout>
            @endif
            @if ($errors->any())
                <flux:callout variant="danger" class="mb-6">{{ $errors->first() }}</flux:callout>
            @endif

            <form method="POST" action="{{ route('settings.system.update') }}" class="space-y-8">
                @csrf
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Email delivery') }}</flux:heading>
                    <flux:select name="mail_transport" :label="__('Transport')">
                        @foreach (['smtp' => 'SMTP', 'ses' => 'Amazon SES', 'postmark' => 'Postmark', 'resend' => 'Resend', 'sendmail' => 'Sendmail'] + (config('mail.allow_log_transport') ? ['log' => 'Log (diagnostic)'] : []) as $value => $label)
                            <option value="{{ $value }}" @selected(old('mail_transport', \App\Support\SystemSettings::mailTransport()) === $value)>{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input name="mail_from_address" type="email" :label="__('From address')" :value="old('mail_from_address', \App\Support\SystemSettings::mailFromAddress())" required />
                        <flux:input name="mail_from_name" :label="__('From name')" :value="old('mail_from_name', \App\Support\SystemSettings::mailFromName())" required />
                        <flux:input name="smtp_host" :label="__('SMTP host')" :value="old('smtp_host', \App\Support\SystemSettings::value('mail.smtp.host', config('mail.mailers.smtp.host')))" />
                        <flux:input name="smtp_port" type="number" min="1" max="65535" :label="__('SMTP port')" :value="old('smtp_port', \App\Support\SystemSettings::value('mail.smtp.port', config('mail.mailers.smtp.port')))" />
                        <flux:input name="smtp_scheme" :label="__('SMTP scheme (tls or smtps)')" :value="old('smtp_scheme', \App\Support\SystemSettings::value('mail.smtp.scheme', config('mail.mailers.smtp.scheme')))" />
                        <flux:input name="smtp_username" :label="__('SMTP username')" :value="old('smtp_username', \App\Support\SystemSettings::value('mail.smtp.username', config('mail.mailers.smtp.username')))" />
                        <flux:input name="ses_region" :label="__('SES region')" :value="old('ses_region', \App\Support\SystemSettings::value('mail.ses.region', config('services.ses.region')))" />
                    </div>
                    @foreach (['smtp_password' => 'SMTP password', 'ses_key' => 'SES access key', 'ses_secret' => 'SES secret', 'postmark_token' => 'Postmark token', 'resend_key' => 'Resend API key'] as $key => $label)
                        <div class="grid gap-3 sm:grid-cols-[1fr_12rem]">
                            <flux:input name="{{ $key }}" type="password" :label="$label" autocomplete="new-password" :description="\App\Support\SystemSettings::hasSecret($key) ? __('A protected value is stored.') : __('Environment fallback is used until replaced.')" />
                            <flux:select name="{{ $key }}_action" :label="__('Credential action')">
                                <option value="keep">{{ __('Keep') }}</option><option value="replace">{{ __('Replace') }}</option><option value="clear">{{ __('Clear override') }}</option>
                            </flux:select>
                        </div>
                    @endforeach
                    <flux:text>{{ __('Sendmail uses the server-configured command; it cannot be changed here.') }}</flux:text>
                </div>

                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Browser sessions') }}</flux:heading>
                    <flux:input name="session_lifetime" type="number" min="5" max="1440" :label="__('Idle lifetime (minutes)')" :value="old('session_lifetime', \App\Support\SystemSettings::sessionLifetime())" required />
                    <input type="hidden" name="session_expire_on_close" value="0">
                    <flux:checkbox name="session_expire_on_close" value="1" :checked="old('session_expire_on_close', \App\Support\SystemSettings::expireOnClose())" :label="__('Expire sessions when the browser closes')" />
                </div>

                <div class="space-y-6">
                    <flux:heading size="lg">{{ __('Social sign-in') }}</flux:heading>
                    @foreach (['google' => 'Google', 'microsoft' => 'Microsoft', 'github' => 'GitHub'] as $provider => $label)
                        <flux:card class="space-y-4">
                            <input type="hidden" name="{{ $provider }}_enabled" value="0">
                            <flux:checkbox name="{{ $provider }}_enabled" value="1" :checked="old($provider.'_enabled', \App\Support\SystemSettings::value('oauth.'.$provider.'.enabled', config('services.oauth.'.$provider.'.client_id') ? '1' : '0') === '1')" :label="__('Enable :provider', ['provider' => $label])" />
                            <flux:input name="{{ $provider }}_client_id" :label="__('Client ID')" :value="old($provider.'_client_id', \App\Support\SystemSettings::value('oauth.'.$provider.'.client_id', config('services.oauth.'.$provider.'.client_id')))" />
                            <div class="grid gap-3 sm:grid-cols-[1fr_12rem]">
                                <flux:input name="oauth_{{ $provider }}_secret" type="password" :label="__('Client secret')" autocomplete="new-password" :description="\App\Support\SystemSettings::hasSecret('oauth_'.$provider.'_secret') ? __('A protected value is stored.') : __('Environment fallback is used until replaced.')" />
                                <flux:select name="oauth_{{ $provider }}_secret_action" :label="__('Credential action')"><option value="keep">{{ __('Keep') }}</option><option value="replace">{{ __('Replace') }}</option><option value="clear">{{ __('Clear override') }}</option></flux:select>
                            </div>
                            <flux:text>{{ __('Callback URL: :url', ['url' => route('oauth.callback', $provider)]) }}</flux:text>
                            @if ($provider === 'microsoft')
                                <flux:input name="microsoft_tenant" :label="__('Microsoft tenant')" :value="old('microsoft_tenant', \App\Support\SystemSettings::value('oauth.microsoft.tenant', config('services.oauth.microsoft.tenant', 'common')))" />
                            @endif
                        </flux:card>
                    @endforeach
                    <flux:text>{{ __('Password sign-in remains available even when social sign-in is enabled.') }}</flux:text>
                </div>

                <flux:button type="submit" variant="primary">{{ __('Save system settings') }}</flux:button>
            </form>

            <form method="POST" action="{{ route('settings.system.test-mail') }}" class="mt-8 flex items-end gap-3">
                @csrf
                <flux:input name="email" type="email" :label="__('Test recipient')" :value="auth()->user()->email" required />
                <flux:button type="submit">{{ __('Send test email') }}</flux:button>
            </form>
        </x-pages::settings.layout>
    </section>
</x-layouts::app>
