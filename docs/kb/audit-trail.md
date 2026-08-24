# Audit trail

Every decision is added as a new history entry. ApproveDesk does not change or remove those entries.

On a request you will see who acted, what they did, and when. Automatic steps and email-link approvals appear in the same list.

Comment edits write the previous text into an append-only revision list. Manual reminders are stored as new rows and are not rewritten.

Request field and file changes also appear on **Request history**, with a short before-and-after summary. Those history rows cannot be edited.

## Evidence pack

Administrators and auditors with an active grant can download an **evidence pack** from the request. The pack is a signed zip of the request, workflow version, assignments, actions, comments, delegations, notifications, file hashes, and timestamps.

Open **Administration → Records** and use **Verify an evidence pack** to check a downloaded file. A changed file or a broken signature is refused.

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
