# Approval settings

Open **Settings → Approval settings** to control signed approval links, deadline calendars, and reminder quiet hours. Use these controls when organization-wide approval timing or identity requirements change.

## Approval email links

Set how long a signed approval link stays valid, from 1 to 720 hours. The default is 72 hours. Shorter is safer; longer is more convenient for people who batch email.

Changing the lifetime applies to newly sent links. A link already in someone's inbox keeps the expiry it was issued with.

Turn on **Require sign-in and a second factor for approval links** when the named approver must prove their ApproveDesk identity before deciding. The signed link and its expiry still apply, and the named approver must sign in and verify an authenticator, recovery code, or passkey in that browser session. A different signed-in account is refused without showing the request.

The setting affects every link still within its original expiry, including messages already sent. The person enabling it must already have a second factor and verify it in the current session. People without a factor are directed to **Settings → Security**.

This option covers messages that open the ApproveDesk approval page. Direct approval buttons inside Teams and Slack use their own signed action. Keep `{{approval_url}}` in action-required email copy.

## Business calendar

Set working days, optional office hours, and holiday dates. Workflow steps using **Business hours** count only working days that are not holidays and only the office-hour window when one is set.

Leave both office-time fields empty to count every hour of a working day. Do not enter 00:00–23:59 to mean all day; that is 23 hours and 59 minutes.

Times use the installation timezone shown on this screen. Changes immediately affect in-progress Business-hours deadlines, overdue badges, automatic SLA actions, and estimated due times. Steps using **Elapsed hours** ignore this calendar. Approval-link lifetime is also a separate elapsed clock.

## Reminder quiet hours

Set an optional start and end time. A manual reminder recorded inside the window is delivered when the window ends. Overnight ranges such as 22:00–07:00 are allowed. Leave both fields empty to deliver reminders immediately.

Quiet hours do not change office hours, workflow deadlines, or approval-link expiry.

## What not to do

- Do not extend link lifetime without considering the extra exposure window.
- Do not enable second-factor enforcement before affected approvers can enroll.
- Do not enter an almost-all-day office window when an empty window means the full working day.
- Do not treat quiet hours as a change to workflow due dates.
