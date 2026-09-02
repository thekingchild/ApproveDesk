# Notification channels

## Definition

A notification channel is a way ApproveDesk tells someone that a request needs them. **In-app** (the bell) and **email** are always on. **Microsoft Teams**, **Slack**, **WhatsApp**, and **Telegram** are optional. **Browser push** is optional per person on their own device.

## When to use it

Open **Settings → Notification channels** when this organisation should also notify people in chat, override email wording, map WhatsApp templates, or inspect delivery. People still choose email timing on **Settings → Notifications**.

## How the live screens work

### Overview

Cards show whether each optional channel is off, missing credentials, or ready. In-app and email do not appear as switches; they stay on.

### Email copy

Pick a notification kind, edit the subject and body, preview it, save it, or restore the default. Merge fields such as `{{title}}` and `{{approval_url}}` are replaced when a message is sent. **Action required** copy must include `{{approval_url}}`. Saving does not change signed-link lifetime or whether the named approver must sign in.

Administrators control that requirement on **Settings → Approval settings**. When it is on, the link in email, Telegram, WhatsApp, or another message still opens the request, but only after the named approver signs in and verifies a second factor in that browser session.

### WhatsApp templates and email copy are separate

WhatsApp template text is separate from email copy. Editing **Email copy** changes email and Telegram wording. It does not change a WhatsApp message.

Create the wording in WhatsApp Manager, submit it to Meta, and wait for the template status to become **Approved**. ApproveDesk then links a notification kind, such as **Action required**, to that approved template and fills its numbered placeholders. ApproveDesk never sends these events as free-form WhatsApp text.

For example, this Meta template:

```text
Hello {{1}}, request {{2}} ({{3}}), submitted by {{4}}, needs your approval. Review it here: {{5}}
```

can be filled as:

```text
Hello Ada Lovelace, request SUB-2026-00001 (Annual leave), submitted by Charles Babbage, needs your approval. Review it here: https://approvedesk.example/...
```

The words and punctuation come from the Meta template. ApproveDesk supplies only the values represented by `{{1}}`, `{{2}}`, and the remaining placeholders.

### Set up WhatsApp

1. In WhatsApp Manager, create the templates you need. The samples below are suitable starting points for the **Utility** category, but Meta makes the final category and approval decision.
2. Keep each template name in lowercase with underscores, for example `approvedesk_action_required`.
3. Submit each template and wait for **Approved** status. If Meta rejects one, edit and resubmit it in WhatsApp Manager.
4. In ApproveDesk, open **Settings → Notification channels → Chat channels**.
5. Save the WhatsApp Business account ID, phone number ID, and access token. Then select **Test connection**.
6. Select **Fetch templates**. ApproveDesk lists the approved templates returned by Meta.
7. Under **WhatsApp template mapping**, choose a notification kind, template, and language.
8. Enter the bindings shown with the matching sample below, then select **Save mapping**.
9. Enable WhatsApp and select **Save channel options**.
10. Make sure each recipient has a phone number in international E.164 format, such as `+2348012345678`.

The mapping language must match the language code fetched with the Meta template. Map only the kinds you want to send. An unmapped kind is skipped without stopping email, the in-app notification, or the approval workflow.

### How to enter bindings

A binding connects an ApproveDesk value to a numbered placeholder in the Meta template:

```text
recipient_name=1
reference_number=2
```

Enter binding lines in the same order as the placeholders: the line ending in `=1` first, then `=2`, and so on. Do not rearrange the lines even when the numbers on the right are correct.

Use the exact binding names shown below. Do not include braces around the number: enter `recipient_name=1`, not `recipient_name={{1}}`.

## Sample WhatsApp templates and mappings

The sample wording can be copied into WhatsApp Manager. You may change the fixed wording, but keep the same numbered placeholders and their order unless you also change the binding order in ApproveDesk.

### Action required

**Suggested Meta template name:** `approvedesk_action_required`

**Template body:**

```text
Hello {{1}}, request {{2}} ({{3}}), submitted by {{4}}, needs your approval. Open the secure review page: {{5}}
```

**ApproveDesk bindings:**

```text
recipient_name=1
reference_number=2
title=3
submitter_name=4
approval_url=5
```

The link opens the review or confirmation page. Opening it does not by itself approve the request.

### Decision

**Suggested Meta template name:** `approvedesk_decision`

**Template body:**

```text
Hello {{1}}, the status of request {{2}} ({{3}}) has changed. View the current decision and history: {{4}}
```

**ApproveDesk bindings:**

```text
recipient_name=1
reference_number=2
title=3
submission_url=4
```

The current WhatsApp mapping provides the request link rather than a separate decision placeholder, so use neutral wording such as “status has changed.” The page shows whether the request was approved, rejected, returned, or otherwise updated.

### Reminder

**Suggested Meta template name:** `approvedesk_reminder`

**Template body:**

```text
Hello {{1}}, this is a reminder about request {{2}} ({{3}}). Open the request for details: {{4}}
```

**ApproveDesk bindings:**

```text
recipient_name=1
reference_number=2
title=3
submission_url=4
```

### Mention

**Suggested Meta template name:** `approvedesk_mention`

**Template body:**

```text
Hello {{1}}, you were mentioned on request {{2}} ({{3}}). Open the conversation: {{4}}
```

**ApproveDesk bindings:**

```text
recipient_name=1
reference_number=2
title=3
submission_url=4
```

### Comment

**Suggested Meta template name:** `approvedesk_comment`

**Template body:**

```text
Hello {{1}}, a comment was added to request {{2}} ({{3}}). Read the conversation: {{4}}
```

**ApproveDesk bindings:**

```text
recipient_name=1
reference_number=2
title=3
submission_url=4
```

### Follower update

**Suggested Meta template name:** `approvedesk_follower_update`

**Template body:**

```text
Hello {{1}}, request {{2}} ({{3}}), which you follow, has been updated. View its current status: {{4}}
```

**ApproveDesk bindings:**

```text
recipient_name=1
reference_number=2
title=3
submission_url=4
```

### Delegation

**Suggested Meta template name:** `approvedesk_delegation`

**Template body:**

```text
Hello {{1}}, approval work for request {{2}} ({{3}}) has been delegated to you. Open the request: {{4}}
```

**ApproveDesk bindings:**

```text
recipient_name=1
reference_number=2
title=3
submission_url=4
```

### Digest

**Suggested Meta template name:** `approvedesk_digest`

**Template body:**

```text
Hello {{1}}, you have {{2}} ApproveDesk notifications waiting. Sign in to review them.
```

**ApproveDesk bindings:**

```text
recipient_name=1
count=2
```

This template reports the number of waiting notifications. It does not include a request link because a digest can contain several requests.

## Check a mapping before relying on it

After saving a mapping:

1. Confirm WhatsApp is enabled and the Overview card says **Ready**.
2. Confirm the selected Meta template still has **Approved** status.
3. Confirm the recipient has a valid international phone number.
4. Trigger the matching notification kind with a test request.
5. Open the **Delivery** tab and check whether the attempt is sent, skipped, deferred, or failed.

A connection test checks the credentials. It does not prove that every template mapping or recipient phone number is valid.

### Other chat channels

Enable a channel, paste credentials on its save form (secrets are not shown again), and test it. Disabling a channel leaves its credentials in place. Slack needs an interactivity URL. Telegram needs the webhook URL shown on its card.

Teams and Slack action-required messages can include an **Approve** button. That button uses a separate signed token with the same stale-step rules as email. Opening the message does not approve. The approval-link second-factor option does not cover these direct buttons; use **Open in ApproveDesk** when the decision must happen in a signed-in session.

### People

Map a person to a Teams, Slack, or Telegram ID. **Remove** clears that mapping. WhatsApp uses the E.164 phone number on the user record. People without a valid phone number are skipped on WhatsApp.

### Delivery

Recent optional-channel attempts show sent, skipped, deferred, or failed. Reminder quiet hours also delay chat and browser push; they do not delay the inbox. Failed action-required chat retries up to three times. A failed chat delivery does not fail the approval.

Telegram sends a message to the linked account. ApproveDesk does not keep an always-on chat connection. WhatsApp sends only when the kind is mapped to an approved template.

### Your account

**Settings → Notifications** is still Immediate, Daily, Weekly, or In-app only for **email**. In-app only does not mute chat or browser push. You can install the app, allow browser notifications, and link or unlink Telegram when the organisation has Telegram ready.

## What not to do

- Do not copy the Email copy body into the ApproveDesk bindings box. WhatsApp wording belongs in WhatsApp Manager.
- Do not paste bot tokens on a personal Notifications page.
- Do not treat a successful connection test as proof that every mapping can send.
- Do not map a notification kind to a template that is pending, paused, or rejected.
- Do not rearrange binding lines; keep them in numbered placeholder order.
- Do not remove `{{approval_url}}` from action-required email copy.
- Do not treat a failed chat delivery as a failed approval.
- Do not approve from a stale Teams or Slack button; ApproveDesk will refuse it.
