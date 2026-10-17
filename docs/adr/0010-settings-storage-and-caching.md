# 0010. Runtime settings live in one key/value table, cached as a single array

Date: 2026-10-17

Status: Accepted (recorded retroactively; `settings` table in force since v2.0.0, encrypted system secrets since v2.8.5, single cached array since Unreleased (RV-PERF, lead L6))

## Context

ApproveDesk targets cPanel/Plesk shared hosting. Administrators there often cannot edit `.env`, run `php artisan config:cache`, or restart PHP. The web installer writes only bootstrap values to `.env` (`APP_KEY`, database, `CACHE_STORE=file`, `SESSION_DRIVER=file`; `app/Services/Install/Installer.php:150`). Yet branding, registration, security toggles, backup schedules, notification channels, outbound mail, session lifetime, and OAuth sign-in all have to be changeable from the browser. They are read on nearly every request (login page, layout chrome, middleware, mail), so reading them must not cost a query per key.

## Decision

- **One table.** `settings` has a unique string `key` and a nullable text `value` (`2026_08_16_231900_create_settings_table.php`). Keys are namespaced by prefix (`brand.*`, `auth.*`, `backup.*`, `privacy.*`, `system.*`, `turnstile.*`, …). Values are strings; each reader parses its own types. No table per feature.
- **`Setting::get/put/forget`** (`app/Models/Setting.php`) are the only entry points. `get()` returns the caller's default for a missing key. Before installation it swallows read errors and returns the default.
- **One cache entry, memoised per request.** `Setting::cached()` loads every row with one `pluck('value', 'key')` into the `settings.all` key, kept forever in the configured store and wrapped in `Cache::memo()`. A request or queued job reads the store at most once. Defaults are never cached.
- **Flush on every write.** `put()`, `forget()`, and the model's `saved`/`deleted` events drop `settings.all`. `put()` on a `system.*` key also resets `SystemSettings`' per-request copy. Database promote calls `Setting::flushCache()` after replacing the table (ADR 0011).
- **Typed readers in `app/Support`** own keys, defaults, and parsing for their group: `Branding`, `RegistrationSettings`, `SecuritySettings`, `BackupSettings` (clamps retention to 3–90 days and validates `HH:MM`), and `SystemSettings`.
- **`SystemSettings` overlays `config()`.** It reads only `system.*` keys from the cached array. At boot, `AppServiceProvider::configureSystemRuntime()` calls `applyRuntimeConfig()` once installed, which overrides mail transport, from-address, SMTP/SES/Postmark/Resend credentials, session lifetime, expire-on-close, and Google/Microsoft/GitHub OAuth clients. `.env` values remain the fallback when no override is stored.
- **Secrets are encrypted with `Crypt` (`APP_KEY`)** before they reach the table. `SystemSettings::putSecret()` stores them under `system.secret.<name>`. `secret()` decrypts, logs a warning, and falls back when decryption fails. The UI only ever sees `SystemSettings::MASK`. Turnstile, notification-channel credentials, and the web-push key encrypt their own values in `settings` the same way. Backup destinations and integrations keep encrypted credentials on their own rows instead.
- **Cache store is the file driver on production installs.** No Redis.

## Consequences

- Settings can be changed from the browser without file access or a cache rebuild, and a typical page costs one cache read for all of them.
- Any write rewrites the whole cached array, which is fine at the current scale (tens to low hundreds of keys, rare writes).
- Values are untyped strings. Boolean conventions vary (`'1'`/`'0'` in `SecuritySettings` and `BackupSettings`, `'true'`/`'false'` in `Branding`), so a key must be read through the reader that wrote it.
- Encrypted settings are tied to `APP_KEY`. Rotating or losing it, or restoring a database backup onto an install with a different key, leaves those secrets unreadable. They then fall back to `.env` or appear unset and must be re-entered.
- Readers are not the only callers. The review counted 45 raw `Setting::get` calls in 17 files outside typed readers (RV-ARC-12). A grep on this branch finds 38 calls in 18 files under `app/` and 23 more in 5 Blade pages, 18 of them on Branding. Keys and defaults are therefore repeated, for example retention in `ApplyRetentionJob` and the Records page. ADR 0007 proposes that new code reads only through a reader and that existing calls move when their area is next touched.

## Open questions

- `.env.example` ships `CACHE_STORE=database`, and `config/cache.php` defaults to `database`. The installer and `docs/DEPLOYMENT.md` use `file`, and AGENTS.md says "file cache". Should `.env.example` change to `file`?
- `SystemSettings` binds its copy with `app()->instance()`, which is not a scoped binding, and `applyRuntimeConfig()` runs only at boot. A queue worker started before a mail or OAuth change probably keeps the old values until it exits (the scheduled worker stops after 50 seconds). This was not tested.
- `BackupSettings::value()` calls `Schema::hasTable('settings')` on every read, which costs a schema query each time and bypasses the single-read goal.
- The `Setting` class docblock still says reads are "cached forever per key". It is now one key for all settings.
