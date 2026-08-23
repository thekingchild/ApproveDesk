<x-mail::message>
# {{ __('Confirm your email') }}

{{ __('Welcome to :app! Use this code to confirm your email address.', ['app' => config('app.name')]) }}

<div style="text-align:center; margin: 24px 0;">
    <span style="font-size: 28px; letter-spacing: 8px; font-weight: 700;">{{ $code }}</span>
</div>

{{ __('This code expires in :minutes minutes and can be used once.', ['minutes' => $minutes]) }}

{{ __('If you did not create an account, no action is needed — the code will simply expire.') }}

{{ __('Thanks') }},<br>
{{ config('app.name') }}
</x-mail::message>
