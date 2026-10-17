# Audit trail

Every decision is added as a new history entry. ApproveDesk does not change or remove those entries.

On a request you will see who acted, what they did, and when. Automatic steps and email-link approvals appear in the same list.

Comment edits write the previous text into an append-only revision list. Manual reminders are stored as new rows and are not rewritten.

Request field and file changes also appear on **Request history**, with a short before-and-after summary. Body revisions stay collapsed so the full request text is not repeated throughout the history. Select **View body changes** to compare the formatted **Before** and **After** versions; **No body** means that version was empty. The **Body** section shows the current request text. Those history rows cannot be edited.

## Evidence pack

Administrators and auditors with an active grant can download an **evidence pack** from the request. The pack is a signed zip of the request, workflow version, assignments, actions, comments, delegations (including expired and ended ones, with the delegate's name), notifications, file hashes, and timestamps. It also holds the request history (title, body, status, priority, and due-date changes), every earlier wording of edited comments, the file history (each attachment added or removed, by whom, with its hash), and the in-app notifications sent about the request. Notifications sent through Slack, Teams, WhatsApp, Telegram, or browser push are listed with their delivery result. Emails about the request are listed too: who was emailed, what kind of message it was (for example *action required* or *decided*), and when it was handed to the mail server. A daily or weekly digest that mentions the request is listed on it as well. Emails sent before this was added are not listed, and the record shows that the mail server accepted the email, not that it reached the inbox.

Open **Administration → Records** and use **Verify an evidence pack** to check a downloaded file. A changed file, a file added to the zip, a changed manifest, or a broken signature is refused. Packs downloaded before signed manifests show **Unsigned (legacy)** instead of **Verified**.

## What you cannot change

- Recorded approve, reject, return, delegate, escalate, and information-request actions
- A published request-type version and its field definitions
- Files already attached to a submitted request, except as allowed by the current form rules while the requester still owns a draft or a returned request
- Comment revision history, reminder records, request history, and file provenance
- An evidence pack after it is downloaded — if someone alters it, verification fails

If a form needs to change, publish a new version. Old requests keep the version they were submitted against.

If a workflow needs to change, publish a new workflow version. In-flight requests finish on the version they already have. The original step names stay on the history.

If a person leaves, anonymize the account from **Users**. Their name becomes “Former user”. The actions they recorded stay.

Use analytics for trends. Use the request history or an evidence pack when you need evidence of a single case.

See [Records](records.md) for auditor access, legal holds, and retention. See [Security and privacy](security-and-privacy.md) for sensitive fields and download stamps.
