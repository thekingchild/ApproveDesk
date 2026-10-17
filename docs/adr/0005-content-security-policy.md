# 0005. Content Security Policy: nonce-based scripts now, strict policy after a report-only period

Date: 2026-10-17

Status: Accepted (2026-10-17) · Step 1 implemented (`feature/rv-sec-csp-nonce`)

## Context

`App\Http\Middleware\SecurityHeaders` sends `script-src 'self' 'unsafe-inline' 'unsafe-eval'`. With both keywords the policy does not stop injected scripts, so it gives no XSS mitigation (RV-SEC-15, deferred from Wave 1).

The Wave 3 inventory (FINDINGS.md, "CSP"):

- **Inline scripts.** The application has one inline `<script>`, the PWA install button (`settings/notifications.blade.php:120`). It has four inline `on*` handlers (`admin/users.blade.php:568,574`, `reports/approval.blade.php:73`, `install/database.blade.php:17`). Livewire and Flux add their own inline scripts. Both read `Vite::cspNonce()`, but nothing calls `Vite::useCspNonce()`, and `@fluxAppearance` takes the nonce only as an option.
- **`unsafe-eval`.** Alpine's standard build evaluates expressions with `new Function`. Livewire 4.4 ships an Alpine CSP build (`livewire.csp_safe`). About 15 inline `x-data` objects and handlers use arrow functions or globals (`window`, `Intl`) that the CSP build cannot parse, and two `window.*` factories in `resources/js/app.js` would have to become `Alpine.data` registrations. Flux has no CSP mode, and its stubs use expressions that need runtime testing.
- **Styles.** There are `<style>` blocks for theme variables, the auth accent and owner CSS, and 23 `style=` attributes. Flux and `x-show` also write style attributes.
- **Origins.** There are no third-party origins except Turnstile. `Branding::customCodeOrigins()` adds every `https://` host found anywhere in the owner's custom code, comments included, to `script-src`, `connect-src`, `frame-src` and `img-src`. There is no CSP reporting.

Moving straight to the strict policy would break Alpine, and probably parts of Flux, across the app. Keeping the current policy leaves XSS unmitigated.

## Decision

Roll out in three steps. Each step is its own PR with browser tests.

1. **Enforce a nonce-based `script-src` now and keep `'unsafe-eval'`.**
   - `SecurityHeaders` calls `Vite::useCspNonce()` before the response renders. `script-src` becomes `'self' 'nonce-…' 'unsafe-eval'` plus Turnstile and the owner allow-list. `'unsafe-inline'` is removed (browsers ignore it once a nonce is present anyway).
   - Pass the nonce to `@fluxAppearance`.
   - Move the PWA install script into a Vite module, and replace the four inline handlers with Alpine or module listeners.
   - Owner custom code keeps working: each `<script>` it contains is given the request nonce when it is rendered.
   - This blocks injected `<script>` tags, `on*` attributes and `javascript:` URLs. It does not stop injected Alpine attributes (`x-init`), which is why step 3 exists.
2. **Report the strict policy without enforcing it.**
   - Send `Content-Security-Policy-Report-Only: script-src 'self' 'nonce-…'; …` with no `'unsafe-eval'`, alongside the enforced policy.
   - Add `POST /csp-report`: throttled, body capped at 8 KB, written to a dedicated `csp` log channel. It never writes to the database and never sends mail, so it is safe on shared hosting.
   - Rewrite the ~15 incompatible Alpine expressions and register the `window.*` factories with `Alpine.data`.
   - Make `livewire.csp_safe` configurable, off by default, and run the browser suite with it on.
3. **Enforce the strict policy** once the report log is clean and the browser suite passes with `csp_safe` on. Flux is checked screen by screen. A Flux component that needs `eval` is either wrapped or replaced, or this step waits for a Flux CSP mode.

Two rules hold at every step:

- **`style-src` keeps `'unsafe-inline'`.** Flux and `x-show` set style attributes, and style injection is a much smaller risk than script injection. `<style>` blocks still receive the nonce, so dropping `'unsafe-inline'` later stays possible.
- **The owner allow-list replaces host scraping.** A new owner-only setting lists the `https://` origins custom code may load from. It is validated as origins, not URLs. On upgrade it is pre-filled once from what `customCodeOrigins()` finds today, so nothing breaks. After that, origins mentioned only in comments no longer widen the policy.

## Consequences

- After step 1, any inline script without the nonce stops running. That covers the four handlers, any future inline `<script>`, and owner code pasted with event attributes. Browser tests and code review need to catch new ones; Blade views should use `@vite`-loaded modules or Alpine.
- A nonce is unique per response. HTML must not be replayed from a cache with a different header. Before step 1 ships, check that the PWA service worker does not serve cached HTML pages, and adjust it if it does.
- Owners whose custom code relies on hosts mentioned only in comments will need to add them to the allow-list. The Help article for organization settings documents the allow-list.
- The report endpoint adds a public, unauthenticated route. Its throttle and size cap are part of the design, not optional.
- Help (`docs/kb/organization-settings.md`) and `docs/SECURITY.md` are updated in the PRs that change behaviour.
