# Organization settings

These screens sit under Settings and are limited to administrators.

## Branding

Set the organisation identity and the public login screen. The page is organised into two cards — **Application identity** and **Login page** — with a **Live preview** panel beside them so you can check both branding surfaces before saving.

**Application identity** is used after people sign in as well as on login:

- Application name appears next to the logo and in the browser tab. Leave it blank to keep the installed product name.
- Meta description is the short text search engines and link previews can show. It is optional.
- Application logo replaces the default mark in the sidebar, header, and login screen. PNG, JPG, or WebP, up to 1 MB. The card shows the current logo (or your new choice) as a thumbnail the moment you pick a file, with a *Keep current logo instead* link if you change your mind.
- Favicon replaces the browser-tab icon. ICO, PNG, JPG, or WebP, up to 512 KB, with the same preview-and-undo behaviour.
- Allow self-registration sits here because it controls who can create an account. When it is on, two further controls appear:
  - **After someone registers** — require a 6-digit email confirmation (recommended, the default) or grant immediate access. Confirmation codes expire after 10 minutes. Until the person confirms, they cannot sign in.
  - **Registration domains** — one domain per line (for example `bluechipanalytical.com`). Only those email addresses may self-register, including people who create an account with Google or Microsoft. Leave empty to allow any email provider. Subdomains match only when listed. An existing account can still link Google or Microsoft even if that address would not be allowed to self-register. This is an onboarding convenience, not a security boundary: anyone can type any address, so passwords, two-factor, and passkeys remain the real assurance. Administrator-created and imported users ignore this list.

**Login page** settings are the public login screen — what requesters see before they sign in:

- Tagline, accent colour, background image, overlay slider, and optional CSS.
- The background image is shown on large screens in the left panel and on smaller screens behind the sign-in form; its upload card previews the current or chosen image.
- Custom CSS lives under **Advanced: custom CSS** — it is for fine-grained tweaks only and is never required.
- Analytics and custom code lives under **Advanced: analytics and custom code**. Paste a tracking snippet or JavaScript from your analytics provider. ApproveDesk adds it to login, the signed-in application, and the public home page. Leave it empty to add nothing. Only administrators can change this, and it should not contain secrets.
- You can allow or block self-registration. If registration is off, only administrators create accounts.

The **Live preview** panel has two tabs. **Login** shows the background, overlay darkness, tagline, and logo/name as requesters will see them. **Browser** shows how your favicon, application name, and description appear in a browser tab and next to the signed-in header.

Removing a logo, favicon, or background restores the product default; the page states plainly when a removal will happen on save, with a *Keep it* link to cancel. Uploaded files replace the previous file. Identity artwork is public; do not upload confidential images here.

## Approval email links

Set how long a signed approval link stays valid, from 1 to 720 hours. The default is 72 hours. Shorter is safer. Longer is more convenient for people who batch email.

Changing the lifetime applies to newly sent links. Links already in someone’s inbox keep the expiry they were issued with.

Turn on **Require sign-in and a second factor for approval links** when the person named on the link must prove their ApproveDesk identity before deciding. The signed link and its expiry still apply, but the named approver must sign in and verify an authenticator, recovery code, or passkey in that browser session. A different signed-in account is refused without showing the request.

This option is off until an administrator enables it. Enabling or disabling it affects every link that is still within its original expiry, including messages already sent. The administrator enabling it must have a second factor and must have verified it in the current session. People without a factor are sent to **Settings → Security** to add one.

The option covers email, Telegram, WhatsApp, and other messages that open the ApproveDesk approval page. Direct **Approve** buttons inside Teams and Slack use their own signed action and are not covered by this switch. Keep `{{approval_url}}` in action-required email copy; it remains the secure route into the approval page.

## Business calendar

Set working days, optional office hours, and holiday dates (YYYY-MM-DD). A life-ring on Approval settings opens this article.

Workflow steps that use **Business hours** count time only on working days that are not holidays, and only inside the office-hour window when one is set. Leave both time fields empty to count every hour of a working day. That empty state is the 24-hour working day — do not enter 00:00–23:59 to mean “all day”; that window is 23 hours and 59 minutes.

Times are in the installation timezone — the same name shown on Approval settings and Backups. Until that is changed, it is UTC. Changing working days, office hours, or holidays immediately changes in-flight **Business hours** deadlines, overdue badges, and automatic SLA actions. Steps that use **Elapsed hours** still count every hour on the clock and ignore this calendar. Approval link lifetime is a separate elapsed clock. Estimated due time on a request uses the same calendars.

## Reminder quiet hours

Optional start and end times on Approval settings. Manual reminders sent inside the window are recorded at once and delivered when the window ends. Overnight ranges such as 22:00–07:00 are allowed. Empty fields send reminders immediately. This does not change office hours or email-link expiry.

## Notification channels

**Settings → Notification channels** is a separate administrator screen. In-app and email stay on. Teams, Slack, WhatsApp, and Telegram are optional: enable, store credentials, test, and map people. Unconfigured channels skip. They do not replace email. Personal delivery frequency stays on **Settings → Notifications**.

See [Notification channels](notification-channels.md).

## Security and privacy

**Settings → Security & privacy** is a separate administrator screen for download stamps, rate limits, and who opened sensitive fields. People manage their own devices and data export from **Settings → Security** and **Settings → Privacy**. See [Security and privacy](security-and-privacy.md).

## What stays out of these screens

Queues, mail server, database, and installer options are hosting concerns. They are not in this knowledge base.
