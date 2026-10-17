@php
    $mailer = old('mail_transport', \App\Support\SystemSettings::mailTransport());
    $oauth = [
        'google' => [
            'label' => 'Google',
            'enabled' => old('google_enabled', \App\Support\SystemSettings::value('oauth.google.enabled', config('services.oauth.google.client_id') ? '1' : '0')) === '1',
        ],
        'microsoft' => [
            'label' => 'Microsoft',
            'enabled' => old('microsoft_enabled', \App\Support\SystemSettings::value('oauth.microsoft.enabled', config('services.oauth.microsoft.client_id') ? '1' : '0')) === '1',
        ],
        'github' => [
            'label' => 'GitHub',
            'enabled' => old('github_enabled', \App\Support\SystemSettings::value('oauth.github.enabled', config('services.oauth.github.client_id') ? '1' : '0')) === '1',
        ],
    ];
@endphp

<x-layouts::app :title="__('System settings')">
    <section class="w-full">
        @include('partials.settings-heading')

        <x-pages::settings.layout
            :heading="__('System settings')"
            :subheading="__('How ApproveDesk sends email, how long people stay signed in, and optional Google, Microsoft, or GitHub login.')"
            :wide="true"
        >
            <div class="mb-4 flex items-center gap-1">
                <x-ui.help-link article="system-settings" />
            </div>

            @if (session('status'))
                <flux:callout variant="success" class="mb-6">{{ session('status') }}</flux:callout>
            @endif
            @if ($errors->any())
                <flux:callout variant="danger" class="mb-6">{{ $errors->first() }}</flux:callout>
            @endif

            <div
                class="space-y-6"
                data-mailer="{{ $mailer }}"
                data-google="{{ $oauth['google']['enabled'] ? 'true' : 'false' }}"
                data-microsoft="{{ $oauth['microsoft']['enabled'] ? 'true' : 'false' }}"
                data-github="{{ $oauth['github']['enabled'] ? 'true' : 'false' }}"
                x-data="{
                    mailer: $el.dataset.mailer,
                    google: $el.dataset.google === 'true',
                    microsoft: $el.dataset.microsoft === 'true',
                    github: $el.dataset.github === 'true',
                }"
            >
                <form method="POST" action="{{ route('settings.system.update') }}" class="space-y-6">
                    @csrf

                    <flux:card class="space-y-5">
                        <div>
                            <flux:heading size="md">{{ __('Email') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('Used for password resets, approval notices, and other messages ApproveDesk sends.') }}</flux:text>
                        </div>

                        <flux:select
                            name="mail_transport"
                            x-model="mailer"
                            x-on:change="mailer = $event.target.value"
                            :label="__('Send mail with')"
                            :description="__('Pick the service your organisation already uses. Only the fields for that choice are shown.')"
                        >
                            <option value="smtp" @selected($mailer === 'smtp')>{{ __('SMTP mail server') }}</option>
                            <option value="ses" @selected($mailer === 'ses')>{{ __('Amazon SES') }}</option>
                            <option value="postmark" @selected($mailer === 'postmark')>{{ __('Postmark') }}</option>
                            <option value="resend" @selected($mailer === 'resend')>{{ __('Resend') }}</option>
                            <option value="sendmail" @selected($mailer === 'sendmail')>{{ __('This server’s sendmail') }}</option>
                            @if (config('mail.allow_log_transport'))
                                <option value="log" @selected($mailer === 'log')>{{ __('Log only (diagnostic)') }}</option>
                            @endif
                        </flux:select>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:input
                                name="mail_from_address"
                                type="email"
                                :label="__('From address')"
                                :description="__('The address people see as the sender.')"
                                :value="old('mail_from_address', \App\Support\SystemSettings::mailFromAddress())"
                                required
                            />
                            <flux:input
                                name="mail_from_name"
                                :label="__('From name')"
                                :description="__('Usually your company name.')"
                                :value="old('mail_from_name', \App\Support\SystemSettings::mailFromName())"
                                required
                            />
                        </div>

                        <div class="space-y-4" x-show="mailer === 'smtp'" x-cloak>
                            <flux:separator />
                            <flux:text>{{ __('Ask your email provider for these SMTP details if you do not already have them.') }}</flux:text>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input name="smtp_host" :label="__('Server host')" :value="old('smtp_host', \App\Support\SystemSettings::value('mail.smtp.host', config('mail.mailers.smtp.host')))" />
                                <flux:input name="smtp_port" type="number" min="1" max="65535" :label="__('Port')" :description="__('587 is common for STARTTLS. 465 is common for SMTPS.')" :value="old('smtp_port', \App\Support\SystemSettings::value('mail.smtp.port', config('mail.mailers.smtp.port')))" />
                                <flux:select name="smtp_scheme" :label="__('Encryption')">
                                    @php($scheme = (string) old('smtp_scheme', \App\Support\SystemSettings::value('mail.smtp.scheme', config('mail.mailers.smtp.scheme'))))
                                    <option value="" @selected($scheme === '')>{{ __('Automatic') }}</option>
                                    <option value="tls" @selected($scheme === 'tls')>{{ __('STARTTLS') }}</option>
                                    <option value="smtps" @selected($scheme === 'smtps')>{{ __('SMTPS') }}</option>
                                </flux:select>
                                <flux:input name="smtp_username" :label="__('Username')" :value="old('smtp_username', \App\Support\SystemSettings::value('mail.smtp.username', config('mail.mailers.smtp.username')))" autocomplete="off" />
                            </div>
                            @include('pages.settings.partials.system-secret', [
                                'name' => 'smtp_password',
                                'label' => __('Password'),
                            ])
                        </div>

                        <div class="space-y-4" x-show="mailer === 'ses'" x-cloak>
                            <flux:separator />
                            <flux:text>{{ __('Use an IAM user that is allowed to send through Amazon SES. The region must match the SES account.') }}</flux:text>
                            <flux:input name="ses_region" :label="__('AWS region')" :description="__('Example: eu-west-1')" :value="old('ses_region', \App\Support\SystemSettings::value('mail.ses.region', config('services.ses.region')))" />
                            @include('pages.settings.partials.system-secret', ['name' => 'ses_key', 'label' => __('Access key ID')])
                            @include('pages.settings.partials.system-secret', ['name' => 'ses_secret', 'label' => __('Secret access key')])
                        </div>

                        <div class="space-y-4" x-show="mailer === 'postmark'" x-cloak>
                            <flux:separator />
                            <flux:text>{{ __('Paste the server API token from Postmark. The token is stored encrypted and is not shown again.') }}</flux:text>
                            @include('pages.settings.partials.system-secret', ['name' => 'postmark_token', 'label' => __('Postmark server token')])
                        </div>

                        <div class="space-y-4" x-show="mailer === 'resend'" x-cloak>
                            <flux:separator />
                            <flux:text>{{ __('Paste the API key from Resend. It is stored encrypted and is not shown again.') }}</flux:text>
                            @include('pages.settings.partials.system-secret', ['name' => 'resend_key', 'label' => __('Resend API key')])
                        </div>

                        <flux:callout variant="info" icon="information-circle" x-show="mailer === 'sendmail'" x-cloak>
                            {{ __('Sendmail uses the command already configured on this server. That command cannot be edited here.') }}
                        </flux:callout>

                        <flux:callout variant="warning" icon="exclamation-triangle" x-show="mailer === 'log'" x-cloak>
                            {{ __('Log delivery writes messages to the application log instead of sending them. Use it only while diagnosing a local copy.') }}
                        </flux:callout>
                    </flux:card>

                    <flux:card class="space-y-5">
                        <div>
                            <flux:heading size="md">{{ __('Signed-in sessions') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('These rules apply to new sign-ins. They do not change cookie security settings.') }}</flux:text>
                        </div>

                        <flux:input
                            name="session_lifetime"
                            type="number"
                            min="5"
                            max="1440"
                            :label="__('Sign people out after this many idle minutes')"
                            :description="__('5–1,440 minutes. After this idle time, they must sign in again.')"
                            :value="old('session_lifetime', \App\Support\SystemSettings::sessionLifetime())"
                            required
                        />

                        <input type="hidden" name="session_expire_on_close" value="0">
                        <div class="space-y-2">
                            <flux:checkbox
                                name="session_expire_on_close"
                                value="1"
                                :checked="old('session_expire_on_close', \App\Support\SystemSettings::expireOnClose())"
                                :label="__('End the session when the browser is closed')"
                            />
                            <flux:text>{{ __('When this is on, Remember me is hidden on Login and social sign-in does not keep a long-lived device cookie.') }}</flux:text>
                        </div>
                    </flux:card>

                    <flux:card class="space-y-5">
                        <div>
                            <flux:heading size="md">{{ __('Social sign-in') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('Optional. People can still sign in with email and password. Create an app in each provider, then paste the client ID and secret here.') }}</flux:text>
                        </div>

                        @foreach ($oauth as $provider => $meta)
                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <flux:heading size="sm">{{ $meta['label'] }}</flux:heading>
                                        <flux:text class="mt-1">{{ __('Show a “Continue with :provider” button on Login.', ['provider' => $meta['label']]) }}</flux:text>
                                    </div>
                                    <flux:badge :color="$meta['enabled'] ? 'lime' : 'zinc'" size="sm">
                                        {{ $meta['enabled'] ? __('On') : __('Off') }}
                                    </flux:badge>
                                </div>

                                <input type="hidden" name="{{ $provider }}_enabled" value="0">
                                <div class="mt-4">
                                    <flux:checkbox
                                        name="{{ $provider }}_enabled"
                                        value="1"
                                        x-model="{{ $provider }}"
                                        x-on:change="{{ $provider }} = $event.target.checked"
                                        :checked="$meta['enabled']"
                                        :label="__('Offer :provider sign-in', ['provider' => $meta['label']])"
                                    />
                                </div>

                                <div class="mt-4 space-y-4" x-show="{{ $provider }}" x-cloak>
                                    <flux:input
                                        name="{{ $provider }}_client_id"
                                        :label="__('Client ID')"
                                        :value="old($provider.'_client_id', \App\Support\SystemSettings::value('oauth.'.$provider.'.client_id', config('services.oauth.'.$provider.'.client_id')))"
                                        autocomplete="off"
                                    />
                                    @include('pages.settings.partials.system-secret', [
                                        'name' => 'oauth_'.$provider.'_secret',
                                        'label' => __('Client secret'),
                                    ])
                                    <div>
                                        <p class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Callback URL') }}</p>
                                        <p class="mt-1 break-all rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">{{ route('oauth.callback', $provider) }}</p>
                                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Copy this into the :provider app as the authorised redirect URI.', ['provider' => $meta['label']]) }}</p>
                                    </div>
                                    @if ($provider === 'microsoft')
                                        <flux:input
                                            name="microsoft_tenant"
                                            :label="__('Microsoft tenant')"
                                            :description="__('Enter your directory ID to let Microsoft sign-in create and connect accounts. With common or organizations, Microsoft emails are unverified, so only already-connected accounts can sign in.')"
                                            :value="old('microsoft_tenant', \App\Support\SystemSettings::value('oauth.microsoft.tenant', config('services.oauth.microsoft.tenant', 'common')))"
                                        />
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </flux:card>

                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button type="submit" variant="primary">{{ __('Save system settings') }}</flux:button>
                        <flux:text>{{ __('Restart queue workers after you change email settings so waiting jobs use the new mail server.') }}</flux:text>
                    </div>
                </form>

                <flux:card class="space-y-4">
                    <div>
                        <flux:heading size="md">{{ __('Send a test email') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Save the settings above first, then send one message to confirm delivery.') }}</flux:text>
                    </div>
                    <form method="POST" action="{{ route('settings.system.test-mail') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        @csrf
                        <div class="min-w-0 flex-1">
                            <flux:input name="email" type="email" :label="__('Send test to')" :value="auth()->user()->email" required />
                        </div>
                        <flux:button type="submit">{{ __('Send test email') }}</flux:button>
                    </form>
                </flux:card>
            </div>
        </x-pages::settings.layout>
    </section>
</x-layouts::app>
