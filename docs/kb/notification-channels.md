# Notification channels

## Definition

A notification channel is a way ApproveDesk tells someone that a request needs them. **In-app** (the bell) and **email** are always on. **Microsoft Teams**, **Slack**, **WhatsApp**, and **Telegram** are optional. **Browser push** is optional per person on their own device.

## When to use it

Open **Settings → Notification channels** when this organisation should also notify people in chat, override email wording, map WhatsApp templates, or inspect delivery. People still choose email timing on **Settings → Notifications**.

## How the live screens work

### Overview

Cards show whether each optional channel is off, missing credentials, or ready. In-app and email do not appear as switches; they stay on.

### Email copy

Pick a notification kind, edit subject and body, preview, save, or restore the default. Merge fields such as `{{title}}` and `{{approval_url}}` are replaced when a message is sent. **Action required** copy must include `{{approval_url}}`. Saving does not change signed-link lifetime or whether the named approver must sign in.

Administrators control that requirement on **Settings → Approval settings**. When it is on, the link in email, Telegram, WhatsApp, or another message still opens the request, but only after the named approver signs in and verifies a second factor in that browser session.

### Chat channels

Enable a channel, paste credentials on the save form (secrets are not shown again), and test. Disable leaves credentials in place. Slack needs an interactivity URL. Telegram needs the webhook URL ApproveDesk shows on that card. WhatsApp fetch loads Meta templates; map each kind to an **approved** template. Unmapped or paused templates skip WhatsApp. ApproveDesk never sends free-form WhatsApp text.

Teams and Slack action-required messages can include an **Approve** button. That button uses a separate signed token with the same stale-step rules as email. Opening the message does not approve. The approval-link second-factor option does not cover these direct buttons; use **Open in ApproveDesk** when the decision must happen in a signed-in session.

### People

Map a person to a Teams, Slack, or Telegram id. **Remove** clears that mapping. WhatsApp uses the E.164 phone on the user record. Unmapped people are skipped on that channel.

### Delivery

Recent optional-channel attempts show sent, skipped, deferred, or failed. Reminder quiet hours also delay chat and browser push; they do not delay the inbox. Failed action-required chat retries up to three times. A failed chat delivery does not fail the approval.

Telegram sends a message to the linked account. ApproveDesk does not keep an always-on chat connection. WhatsApp sends only when the kind is mapped to an approved template.

### Your account

**Settings → Notifications** is still Immediate / Daily / Weekly / In-app only for **email**. In-app only does not mute chat or browser push. You can install the app, allow browser notifications, and link or unlink Telegram when the organisation has Telegram ready.

## What not to do

- Do not paste bot tokens on a personal Notifications page.
- Do not treat a failed chat delivery as a failed approval.
- Do not remove `{{approval_url}}` from action-required email copy.
- Do not expect WhatsApp to send until a kind is mapped to an approved template.
- Do not approve from a stale Teams/Slack button; ApproveDesk will refuse it.
