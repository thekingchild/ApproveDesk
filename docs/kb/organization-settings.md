# Organization settings

These screens sit under Settings and are limited to administrators.

## Branding

Set the organisation identity and the public login screen.

**Application identity** is used after people sign in as well as on login:

- Application name appears next to the logo and in the browser tab. Leave it blank to keep the installed product name.
- Meta description is the short text search engines and link previews can show. It is optional.
- Application logo replaces the default mark in the sidebar, header, and login screen. PNG, JPG, or WebP, up to 1 MB.
- Favicon replaces the browser-tab icon. ICO, PNG, JPG, or WebP, up to 512 KB.

**Login page** settings are the public login screen:

- Tagline, accent colour, background image, overlay, and optional CSS.
- The background image is shown on large screens in the left panel and on smaller screens behind the sign-in form.
- You can allow or block self-registration. If registration is off, only administrators create accounts.

Removing a logo, favicon, or background restores the product default. Uploaded files replace the previous file. Identity artwork is public; do not upload confidential images here.

## Approval email links

Set how long a signed approval link stays valid, from 1 to 720 hours. The default is 72 hours. Shorter is safer. Longer is more convenient for people who batch email.

Changing the lifetime applies to newly sent links. Links already in someone’s inbox keep the expiry they were issued with.

## Business calendar

Set working days, optional office hours, and holiday dates (YYYY-MM-DD). A life-ring on Approval settings opens this article.

Workflow steps that use **Business hours** count time only on working days that are not holidays, and only inside the office-hour window when one is set. Leave both time fields empty to count every hour of a working day. That empty state is the 24-hour working day — do not enter 00:00–23:59 to mean “all day”; that window is 23 hours and 59 minutes.

Times are in the application timezone (UTC). Changing working days, office hours, or holidays immediately changes in-flight **Business hours** deadlines, overdue badges, and automatic SLA actions. Steps that use **Elapsed hours** still count every hour on the clock and ignore this calendar. Email approval link lifetime is a separate elapsed clock. Estimated due time on a request uses the same calendars.

## Reminder quiet hours

Optional start and end times on Approval settings. Manual reminders sent inside the window are recorded at once and delivered when the window ends. Overnight ranges such as 22:00–07:00 are allowed. Empty fields send reminders immediately. This does not change office hours or email-link expiry.

## Notification channels

**Settings → Notification channels** is a separate administrator screen. In-app and email stay on. Teams, Slack, WhatsApp, and Telegram are optional: enable, store credentials, test, and map people. Unconfigured channels skip. They do not replace email. Personal delivery frequency stays on **Settings → Notifications**.

See [Notification channels](notification-channels.md).

## What stays out of these screens

Queues, mail server, database, and installer options are hosting concerns. They are not in this knowledge base.
