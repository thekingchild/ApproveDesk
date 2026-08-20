# Organization settings

These screens sit under Settings and are limited to administrators.

## Branding

Set the login tagline, accent colour, background image, overlay, and optional CSS. This is the public login screen only. It does not restyle the signed-in application.

You can allow or block self-registration. If registration is off, only administrators create accounts.

## Approval email links

Set how long a signed approval link stays valid, from 1 to 720 hours. The default is 72 hours. Shorter is safer. Longer is more convenient for people who batch email.

Changing the lifetime applies to newly sent links. Links already in someone’s inbox keep the expiry they were issued with.

## Business calendar

Set working days, optional office hours, and holiday dates (YYYY-MM-DD). A life-ring on Approval settings opens this article.

Workflow steps that use **Business hours** count time only on working days that are not holidays, and only inside the office-hour window when one is set. Leave both time fields empty to count every hour of a working day. That empty state is the 24-hour working day — do not enter 00:00–23:59 to mean “all day”; that window is 23 hours and 59 minutes.

Times are in the application timezone (UTC). Changing working days, office hours, or holidays immediately changes in-flight **Business hours** deadlines, overdue badges, and automatic SLA actions. Steps that use **Elapsed hours** still count every hour on the clock and ignore this calendar. Email approval link lifetime is a separate elapsed clock.

## What stays out of these screens

Queues, mail server, database, and installer options are hosting concerns. They are not in this knowledge base.
