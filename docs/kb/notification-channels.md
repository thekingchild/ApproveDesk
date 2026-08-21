# Notification channels

## Definition

A notification channel is a way ApproveDesk tells someone that a request needs them. **In-app** (the bell) and **email** are always on. **Microsoft Teams**, **Slack**, **WhatsApp**, and **Telegram** are optional. An administrator turns each option on, stores credentials, tests the connection, and maps people to that system.

SMS and web push exist in the product’s channel list so later work can use them. They have no administrator options yet.

## When to use it

Use **Settings → Notification channels** when people should also hear about approvals in chat. Leave every optional channel off if email and the inbox are enough.

Do not use this screen for how often *you* get email. That is **Settings → Notifications** on your own account: Immediate, Daily, Weekly, or In-app only.

## How the live screen works

Administrators with settings access open **Settings → Notification channels**.

1. Tick the optional channels this organisation will use and save.
2. Paste credentials for that channel. Secrets are stored encrypted. Leave a field blank to keep the current secret. Clear credentials removes them.
3. Test the connection. A successful test is recorded. Failures show a redacted error — tokens are not shown.
4. Map people to Teams, Slack, or Telegram IDs. WhatsApp uses the phone number already stored on the user. Unmapped people are skipped on that channel.
5. Telegram shows a webhook URL. ApproveDesk does not long-poll Telegram.

In-app and email still go out when an optional channel is off, missing credentials, unmapped, or unhealthy. Optional delivery never changes workflow state.

Email template editing and WhatsApp template mapping are later work on this same area. Until WhatsApp templates are mapped, WhatsApp will not send even if it is enabled.

## What not to do

- Do not paste bot tokens into a person’s Notifications settings. Only this administrator screen stores them.
- Do not expect In-app only to mute Teams, Slack, WhatsApp, or Telegram. That radio only stops **email**. Optional channels follow organisation enablement and the mapped identity.
- Do not treat a failed chat delivery as a failed approval. Approvals still complete in ApproveDesk.
- Do not put confidential tokens in Help comments or screenshots.
