@if (\App\Services\Auth\Turnstile::enabled())
    @once
        <script src="{{ \App\Services\Auth\Turnstile::SCRIPT_URL }}" async defer></script>
    @endonce
    <div
        class="cf-turnstile"
        data-sitekey="{{ \App\Services\Auth\Turnstile::siteKey() }}"
        data-appearance="interaction-only"
        data-test="turnstile-widget"
    ></div>
@endif
