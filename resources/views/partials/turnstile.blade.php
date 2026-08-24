@if (\App\Support\Turnstile::enabled())
    @once
        <script src="{{ \App\Support\Turnstile::SCRIPT_URL }}" async defer></script>
    @endonce
    <div
        class="cf-turnstile"
        data-sitekey="{{ \App\Support\Turnstile::siteKey() }}"
        data-appearance="interaction-only"
        data-test="turnstile-widget"
    ></div>
@endif
