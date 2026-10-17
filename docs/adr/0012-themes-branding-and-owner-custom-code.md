# 0012. Branding, application themes, and owner custom code are separate controls

Date: 2026-10-17

Status: Accepted (recorded retroactively; decision in force since v2.0.0 for login branding, extended by Branding identity (2026-08-21), v2.7.2 for owner-only custom code, and v2.8.0 for application themes)

## Context

Organizations want the product to look like their own: a name, logo, favicon, and a branded sign-in page. Signed-in people also want an accessible colour palette they can choose. Some owners need to add an analytics snippet. These needs have different audiences and risks. Identity artwork is public. A palette affects contrast on every signed-in screen. Custom code runs in every session, so it can read anything a signed-in user sees. On shared hosting, `php artisan storage:link` is often missing, so `/storage/...` URLs cannot be relied on.

## Decision

- **Branding is organization identity.** Tagline, accent colour, login background and overlay, logo, favicon, company name (`brand.meta_title`), meta description, and custom CSS are rows in `settings` with `brand.*` keys. Everything reads them through `App\Support\Branding`, which calls `Setting::get`. That method loads the whole table into one cached entry and memoises it per request, so hot pages do not query `settings` (`app/Models/Setting.php:39-66`).
- Branding is saved only through `UpdateBrandingSettingsAction`. The screen at `settings/branding` requires `branding.manage`. A replaced or removed file deletes the previous object on the `public` disk under `branding/`.
- **Branding files are served by `GET /branding/assets/{file}`** (`BrandingAssetController`). The route accepts only a bare file name, an allow-listed image extension, and files under `branding/`. It sends `nosniff`. `Branding::urlFor()` returns `null` for a missing file, so pages fall back to product defaults instead of a broken URL. Nothing depends on `storage:link`.
- **Application themes are a separate palette catalogue.** A `Theme` row holds four colour tokens (`primary_light`, `primary_dark`, `foreground_light`, `foreground_dark`). Three built-in themes are seeded by migration (`approvedesk` is the default). Built-ins cannot be edited or archived.
- `SaveThemeAction` accepts only six-digit hex values, upper-cases them, and rejects a pair with less than 4.5:1 contrast in either mode. `ThemeService::cssVariables()` checks each token again against `^#[0-9A-F]{6}$` before writing it into a fixed `:root`/`.dark` template. It throws rather than output anything else.
- `ThemeService::effectiveFor()` resolves the user's own enabled, unarchived theme. If there is none, it uses the enabled default, then the first enabled theme, then a hard-coded ApproveDesk palette (also used before the `themes` table exists). Enable, default, and archive actions lock the theme rows. The default must stay enabled, at least one theme must stay enabled, and an archived theme cannot become the default.
- Only the System owner manages the catalogue (`ThemePolicy`). Any user may choose an available theme or the organization default. Themes apply only to signed-in pages (`partials/head.blade.php:19-36`). Guests and the login page use the Branding accent colour.
- **Custom CSS** (`brand.custom_css`, max 5000 characters) needs only `branding.manage`. It is output raw inside a `<style>` element in `layouts/auth/split.blade.php` only, which is the sign-in surface. `Branding::customCss()` replaces every `</` with `<\/`, so the CSS cannot close the style element (RV-BUG-39).
- **Custom code** (`brand.custom_code`, max 8000 characters) requires `branding.custom_code.manage`. That permission is elevated and not delegable. It is removed from the seeded `admin` role, so in practice only the System owner holds it, through `Gate::before`. When someone without it saves Branding, the component quietly keeps the stored code, and the action never writes the key.
- **Saving changed custom code requires step-up confirmation (RV-SEC-16).** The password must have been confirmed within `auth.password_timeout`. Otherwise the snippet is kept in the session and the owner is sent to `password.confirm`. The session must also have satisfied a second factor (`SecondFactorAssurance`).
- Custom code is output raw by `partials/head.blade.php:38-41` and injected before `</head>` on the public landing page (`PublicWebsite::injectCustomCode`). `Branding::customCodeHtml()` normalises it but does not sanitise it. Bare JavaScript is wrapped in `<script>` with `</script` removed. In markup, `javascript:`, `data:`, and `vbscript:` `src`/`href` values are stripped, and NUL bytes are dropped. Every `https://host` found in the code is added to `script-src`, `connect-src`, `frame-src`, and `img-src` by `SecurityHeaders`.

## Consequences

- Branding, theme, and appearance (Light/Dark/System) are independent. Changing a theme never changes the login page or public site, and changing Branding never changes signed-in palettes. Help documents each one separately.
- Custom code is trusted owner content, not sanitised input. Its safety depends on the owner, on non-delegable permission, and on step-up confirmation. Any HTTPS origin mentioned in the code widens the CSP on every page.
- Branding reads come from cache. Theme resolution does not. `effectiveFor()` runs one or two `themes` queries on each signed-in page render.
- A theme row with lower-case or malformed hex (for example, edited by hand) makes `cssVariables()` throw. This fails closed instead of emitting unvalidated CSS.

## Open questions

- `BrandingAssetController` still serves `.svg` and `.gif`. Branding uploads accept only JPG/PNG/WebP (and ICO for favicons), and IMPLEMENTATION_PLAN.md excludes SVG uploads as an XSS risk. Should the route's extension list match what uploads allow?
- `UpdateBrandingSettingsAction` gates custom code by `$actor->can(...)` but does not check step-up itself. Only the Livewire component enforces RV-SEC-16. Any future caller of the action must repeat that check.
