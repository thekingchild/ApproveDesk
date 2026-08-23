<x-layouts::auth :title="__('Confirm your email')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Confirm your email')" :description="__('We sent a 6-digit code to :email. Enter it below to activate your account.', ['email' => $email])" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('registration.confirm.verify') }}" class="flex flex-col gap-5">
            @csrf
            <flux:input name="code" :label="__('Confirmation code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus data-test="otp-input" />
            <flux:error name="code" />

            <flux:button variant="primary" type="submit" class="w-full" dusk="confirm-otp">{{ __('Confirm and continue') }}</flux:button>
        </form>

        <form method="POST" action="{{ route('registration.confirm.resend') }}" class="flex items-center justify-center gap-2 text-sm">
            @csrf
            @if (session('status') === null)
                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Did not get the code?') }}</span>
                <button type="submit" class="font-medium underline decoration-dotted" dusk="resend-otp">{{ __('Send a new one') }}</button>
            @endif
        </form>

        <div class="text-center text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('Wrong address?') }}
            <a href="{{ route('register') }}" class="font-medium underline decoration-dotted">{{ __('Register again') }}</a>.
        </div>
    </div>
</x-layouts::auth>
